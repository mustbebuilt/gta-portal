<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\EmployerApprenticeGrant;
use App\Models\EmployerPortalAction;
use App\Models\ExtraTraining;
use App\Models\ProgressReviewDraft;
use App\Models\SmartAction;
use App\Models\User;
use App\Support\AccountAccess;
use App\Support\AccountOnboarding;
use App\Support\EmployerActions;
use App\Support\LearningAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class EmployerPortalController extends Controller
{
    private function manager(Request $r): void
    {
        abort_unless(LearningAccess::manager($r->user()), 403);
    }

    private function privateView(string $view, array $data)
    {
        return response()->view($view, $data)->header('Cache-Control', 'no-store, private');
    }

    public function accounts(Request $r)
    {
        $this->manager($r);

        return $this->privateView('employer-portal.accounts', ['users' => User::where('role', 'employer')->orderBy('name')->paginate(20)]);
    }

    public function createAccount(Request $r)
    {
        $this->manager($r);
        $data = $r->validate(['name' => ['required', 'string', 'max:150'], 'email' => ['required', 'email', 'max:254']]);
        $email = strtolower(trim($data['email']));
        DB::transaction(function () use ($r, $data, $email): void {
            $u = User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless(LearningAccess::manager($u) && $u->auth_version === $r->session()->get('auth_version'), 403);
            if (User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
                throw ValidationException::withMessages(['email' => 'An account already uses that email. Check its identity and role.']);
            }
            $x = new User(['name' => $data['name'], 'email' => $email, 'password' => Str::password(48)]);
            $x->role = 'employer';
            $x->email_verified_at = null;
            $x->save();
            AccountOnboarding::audit($x, $u->id, 'employer_account_created');
        });

        return to_route('employer-accounts.index')->with('status', 'Employer login created without workplace access. Send the controlled invitation separately, then verify their authority for each apprentice.');
    }

    public function invite(Request $r, User $user)
    {
        $this->manager($r);
        $data = $r->validate(['record_version' => ['required', 'integer']]);
        $key = 'employer-invite:'.$r->user()->id;
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429);
        RateLimiter::hit($key, 300);
        try {
            DB::transaction(function () use ($r, $user, $data): void {
                $rows = User::whereIn('id', [$r->user()->id, $user->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $actor = $rows[$r->user()->id];
                $target = $rows[$user->id];
                abort_unless(LearningAccess::manager($actor) && $actor->auth_version === $r->session()->get('auth_version'), 403);
                if ($target->record_version !== (int) $data['record_version']) {
                    throw ValidationException::withMessages(['actions' => 'Account changed. Reload.']);
                }AccountOnboarding::issue($target, 'employer_invite', $actor);
            });
        } catch (TransportExceptionInterface) {
            return back()->withErrors(['actions' => 'Invitation transport was not accepted. No new invitation token was saved.']);
        }

        return back()->with('status', 'Controlled employer invitation prepared. Delivery is not guaranteed; no apprentice access is granted.');
    }

    public function acceptForm(Request $r, int $user, string $token)
    {
        $u = User::find($user);
        abort_unless($u && AccountOnboarding::valid($u, 'employer_invite', $token), 403);

        return $this->privateView('employer-portal.invitation', ['user' => $user, 'token' => $token])->header('Referrer-Policy', 'no-referrer');
    }

    public function accept(Request $r, int $user, string $token)
    {
        $data = $r->validate(['password' => AccountAccess::passwordRules()]);
        $issuer = DB::table('account_onboarding_tokens')->where('user_id', $user)->where('purpose', 'employer_invite')->value('issuer_id');
        DB::transaction(function () use ($user, $token, $issuer, $data): void {
            $rows = User::whereIn('id', array_filter([$user, $issuer]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $u = $rows->get($user);
            abort_unless((int) DB::table('account_onboarding_tokens')->where('user_id', $user)->where('purpose', 'employer_invite')->value('issuer_id') === (int) $issuer && $u && AccountOnboarding::valid($u, 'employer_invite', $token, true), 403);
            $u->email_verified_at = now();
            AccountAccess::setPassword($u, $data['password'], null, 'employer_invitation');
            AccountOnboarding::audit($u, null, 'employer_invite_accepted');
        });
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return to_route('login')->with('status', 'Employer password set and mailbox confirmed. GTA must separately verify workplace authority.');
    }

    public function grants(Request $r, Apprentice $apprentice)
    {
        $this->manager($r);
        $grants = EmployerApprenticeGrant::where('apprentice_id', $apprentice->id)->get()->keyBy('user_id');

        return $this->privateView('employer-portal.grants', ['apprentice' => $apprentice, 'grants' => $grants, 'users' => User::where('role', 'employer')->where('active', true)->orderBy('name')->paginate(20)]);
    }

    public function grant(Request $r, Apprentice $apprentice)
    {
        $this->manager($r);
        $data = $r->validate(['user_id' => ['required', 'integer'], 'version' => ['required', 'integer', 'min:0'], 'identity_reference' => ['required', 'string', 'max:2000'], 'confirmed' => ['accepted']]);
        EmployerActions::grant($r->user(), (int) $r->session()->get('auth_version'), $apprentice, $data);

        return back()->with('status', 'Named employer authority verified for this apprentice. Earlier requests need reissue after any grant change.');
    }

    public function revoke(Request $r, EmployerApprenticeGrant $grant)
    {
        $this->manager($r);
        $data = $r->validate(['version' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:2000']]);
        EmployerActions::revoke($r->user(), (int) $r->session()->get('auth_version'), $grant, (int) $data['version'], $data['reason']);

        return back()->with('status', 'Employer access revoked.');
    }

    public function staff(Request $r, Apprentice $apprentice)
    {
        abort_unless(EmployerActions::staff($r->user(), $apprentice), 403);
        $sources = [];
        foreach (ProgressReviewDraft::where('apprentice_id', $apprentice->id)->where('state', 'Ready for signatures')->get() as $x) {
            $sources[] = ['value' => 'review:'.$x->id, 'label' => 'Frozen review '.$x->id.' · v'.$x->version];
        }foreach (ExtraTraining::where('apprentice_id', $apprentice->id)->whereNull('withdrawn_at')->get()->filter->occurred() as $x) {
            $sources[] = ['value' => 'workplace:'.$x->id, 'label' => 'Training '.$x->id.' · '.$x->topic];
        }foreach (SmartAction::where('apprentice_id', $apprentice->id)->where('owner', 'Employer / workplace mentor')->where('status', '!=', 'Completed')->get() as $x) {
            $sources[] = ['value' => 'target:'.$x->id, 'label' => 'Target '.$x->id.' · '.$x->title];
        }
        $grants = EmployerApprenticeGrant::where('apprentice_id', $apprentice->id)->where('active', true)->get()->filter(fn ($g) => EmployerActions::valid($g, $apprentice));

        return $this->privateView('employer-portal.staff', ['apprentice' => $apprentice, 'sources' => $sources, 'grants' => $grants, 'actions' => EmployerPortalAction::where('apprentice_id', $apprentice->id)->orderByDesc('created_at')->paginate(15)]);
    }

    public function publish(Request $r, Apprentice $apprentice)
    {
        abort_unless(EmployerActions::staff($r->user(), $apprentice), 403);
        $data = $r->validate(['request_token' => ['required', 'uuid'], 'grant_id' => ['required', 'integer'], 'source_choice' => ['required', 'regex:/^(review|workplace|target):[1-9][0-9]*$/D'], 'title' => ['required', 'string', 'max:180'], 'detail' => ['required', 'string', 'max:10000'], 'due_date' => ['nullable', 'date_format:Y-m-d'], 'confirmed' => ['accepted']]);
        [$data['kind'],$data['source_id']] = explode(':', $data['source_choice']);
        EmployerActions::publish($r->user(), (int) $r->session()->get('auth_version'), $apprentice, $data);

        return back()->with('status', 'Checked shareable request published to the named employer login. No reminder message was sent.');
    }

    private function employer(Request $r): void
    {
        abort_unless($r->user()->active && $r->user()->role === 'employer' && $r->user()->email_verified_at, 403);
    }

    public function index(Request $r)
    {
        $this->employer($r);
        $ids = [];
        foreach (EmployerApprenticeGrant::where('user_id', $r->user()->id)->where('active', true)->get() as $g) {
            $a = Apprentice::find($g->apprentice_id);
            if ($a && EmployerActions::valid($g, $a)) {
                $ids[] = $g->id;
            }
        }$visible = EmployerPortalAction::whereIn('employer_apprentice_grant_id', $ids)->get()->filter(fn ($x) => EmployerActions::grantBinding($x, EmployerApprenticeGrant::find($x->employer_apprentice_grant_id)))->pluck('id');

        return $this->privateView('employer-portal.index', ['actions' => EmployerPortalAction::whereIn('id', $visible)->whereIn('employer_apprentice_grant_id', $ids)->where('state', '!=', 'Withdrawn')->orderByRaw("CASE WHEN state = 'Pending' THEN 0 ELSE 1 END")->orderBy('due_date')->orderBy('id')->paginate(15)]);
    }

    public function show(Request $r, EmployerPortalAction $action)
    {
        $this->employer($r);
        $g = EmployerApprenticeGrant::findOrFail($action->employer_apprentice_grant_id);
        $a = Apprentice::findOrFail($action->apprentice_id);
        abort_unless($g->user_id === $r->user()->id && EmployerActions::valid($g, $a) && EmployerActions::grantBinding($action, $g), 403);

        return $this->privateView('employer-portal.action', ['action' => $action, 'apprentice' => $a, 'current' => EmployerActions::current($action, $a, $g)]);
    }

    public function respond(Request $r, EmployerPortalAction $action)
    {
        $this->employer($r);
        $data = $r->validate(['version' => ['required', 'integer'], 'response' => ['required', Rule::in(EmployerActions::RESPONSES)], 'note' => ['required', 'string', 'max:5000'], 'confirmed' => ['accepted']]);
        EmployerActions::respond($r->user(), (int) $r->session()->get('auth_version'), $action, $data);

        return back()->with('status', 'Response saved for GTA to check. It does not sign a review, approve OTJ hours or complete a target.');
    }

    public function staffChange(Request $r, EmployerPortalAction $action)
    {
        $data = $r->validate(['version' => ['required', 'integer'], 'state' => ['required', Rule::in(['Checked', 'Withdrawn'])], 'note' => ['required', 'string', 'max:5000']]);
        EmployerActions::staffChange($r->user(), (int) $r->session()->get('auth_version'), $action, $data);

        return back()->with('status', 'Staff response check/withdrawal saved. Original training/review records remain separate.');
    }
}
