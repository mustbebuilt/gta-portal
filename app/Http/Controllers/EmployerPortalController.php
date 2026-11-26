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

        return app(EmployerSetupController::class)->index($r);
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
        $input = $r->validate(['contact' => ['nullable', 'integer', 'min:1']]);
        $grants = EmployerApprenticeGrant::where('apprentice_id', $apprentice->id)->get()->keyBy('user_id');
        $accounts = User::where('role', 'employer')->where('active', true)->orderBy('name')->orderBy('id');
        $contacts = (clone $accounts)->whereNotNull('email_verified_at')->get(['id', 'name', 'email']);
        if (isset($input['contact'])) {
            abort_unless($contacts->contains('id', (int) $input['contact']), 404);
        }
        $users = $accounts->when(isset($input['contact']), fn ($q) => $q->whereKey($input['contact']))
            ->paginate(20)->withQueryString();

        $replaceable = $grants->filter(fn ($grant) => $grant->active && $grant->employer_id === $apprentice->employer_id)
            ->map(fn ($grant) => ['grant' => $grant, 'user' => $contacts->firstWhere('id', $grant->user_id)])
            ->filter(fn ($row) => $row['user']);

        return $this->privateView('employer-portal.grants', compact('apprentice', 'grants', 'users', 'contacts', 'replaceable'));
    }

    public function grant(Request $r, Apprentice $apprentice)
    {
        $this->manager($r);
        $data = $r->validate([
            'user_id' => ['required', 'integer'],
            'version' => ['required', 'integer', 'min:0'],
            'identity_reference' => ['required', 'string', 'max:2000'],
            'confirmed' => ['accepted'],
            'replace_access' => ['nullable', 'string', 'regex:/^[1-9][0-9]{0,18}:[1-9][0-9]{0,18}$/D'],
            'displayed_employer_id' => ['required_with:displayed_employer_version,replace_access', 'integer', 'min:1'],
            'displayed_employer_version' => ['required_with:displayed_employer_id', 'integer', 'min:1'],
            'linked_employer_id' => ['required_with:linked_employer_version', 'integer', 'min:1'],
            'linked_employer_version' => ['required_with:linked_employer_id', 'integer', 'min:1'],
        ]);
        EmployerActions::grant($r->user(), (int) $r->session()->get('auth_version'), $apprentice, $data);

        if (isset($data['linked_employer_id'])) {
            return to_route('apprentices.edit', ['apprentice' => $apprentice, 'link_employer' => $data['linked_employer_id']])
                ->with('status', 'Workplace access verified for this apprentice.');
        }

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
        $choice = $r->validate(['source' => ['nullable', 'regex:/^(review|workplace|target):[1-9][0-9]*$/D']])['source'] ?? null;
        $sources = collect();
        foreach (ProgressReviewDraft::where('apprentice_id', $apprentice->id)->where('state', 'Ready for signatures')->latest()->get() as $review) {
            $sources->put('review:'.$review->id, [
                'label' => 'Review '.$review->id.' · ready for signatures',
                'title' => 'Feedback on the progress review',
                'detail' => 'Please let us know whether you agree with the progress discussed at the review or would like any changes.',
                'due' => null,
            ]);
        }
        foreach (ExtraTraining::where('apprentice_id', $apprentice->id)->whereNull('withdrawn_at')->latest('training_date')->get()->filter->occurred() as $training) {
            $sources->put('workplace:'.$training->id, [
                'label' => $training->training_date->format('j M Y').' · '.$training->topic,
                'title' => 'Confirm workplace training',
                'detail' => 'Please confirm whether the workplace training took place on '.$training->training_date->format('j M Y').' and tell us if any details need updating.',
                'due' => null,
            ]);
        }
        foreach (SmartAction::with('task')->where('apprentice_id', $apprentice->id)->where('owner', 'Employer / workplace mentor')->where('status', '!=', 'Completed')->latest()->get() as $target) {
            if ($target->currentStatus() === 'Completed' || ($target->learning_task_id && $target->task?->apprentice_id !== $apprentice->id)) {
                continue;
            }
            $deadline = $target->deadline();
            $sources->put('target:'.$target->id, [
                'label' => $target->title,
                'title' => 'Update on a workplace target',
                'detail' => 'Please tell us how the agreed workplace target is progressing and whether any support or changes are needed.',
                'due' => $deadline?->gte(today()) ? $deadline->toDateString() : null,
            ]);
        }
        $grants = EmployerApprenticeGrant::where('apprentice_id', $apprentice->id)->where('active', true)->get()
            ->filter(fn ($grant) => EmployerActions::valid($grant, $apprentice))->values();
        $contacts = User::whereIn('id', $grants->pluck('user_id'))->pluck('name', 'id');

        return $this->privateView('employer-portal.staff', [
            'apprentice' => $apprentice,
            'sources' => $sources,
            'choice' => $choice,
            'grants' => $grants,
            'contacts' => $contacts,
            'actions' => EmployerPortalAction::where('apprentice_id', $apprentice->id)->orderByDesc('created_at')->paginate(15)->withQueryString(),
        ]);
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

        $query = EmployerPortalAction::whereIn('id', $visible)
            ->whereIn('employer_apprentice_grant_id', $ids)->where('state', '!=', 'Withdrawn');
        $pendingCount = (clone $query)->where('state', 'Pending')->count();
        $actions = $query->with('apprentice:id,first_name,last_name')
            ->orderByRaw("CASE WHEN state = 'Pending' THEN 0 ELSE 1 END")
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')->orderBy('due_date')->orderBy('id')->paginate(15);

        return $this->privateView('employer-portal.index', compact('actions', 'pendingCount'));
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
