<?php
namespace App\Http\Controllers;
use App\Models\User;
use App\Support\{AccountAccess, AccountOnboarding, DirectoryCatalog};
use Illuminate\Http\{Request, RedirectResponse};
use Illuminate\Support\Facades\{Auth, DB, Gate, RateLimiter};
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class AccountOnboardingController extends Controller
{
    private function throttle(Request $request): void
    {
        $key = 'onboarding:'.hash('sha256', ($request->user()?->id ?? 'guest').'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) { throw ValidationException::withMessages(['onboarding' => 'Too many attempts. Please try again in five minutes.']); }
        RateLimiter::hit($key, 300);
    }
    public function notice(Request $request)
    {
        return view('auth.email-notice', ['available' => AccountOnboarding::eligible($request->user(), 'verify')]);
    }
    public function sendVerification(Request $request): RedirectResponse
    {
        $this->throttle($request);
        try {
            DB::transaction(function () use ($request) {
                $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                abort_unless($user->auth_version === $request->session()->get('auth_version'), 403);
                AccountOnboarding::issue($user, 'verify');
            });
        } catch (TransportExceptionInterface) {
            return back()->withErrors(['onboarding' => 'The link could not be prepared. Contact your GTA administrator.']);
        }
        return to_route('account.email')->with('status', 'Verification link prepared. Check your test inbox; delivery is not guaranteed.');
    }
    public function confirmForm(Request $request, string $token)
    {
        abort_unless(AccountOnboarding::valid($request->user(), 'verify', $token), 403);
        return response()->view('auth.email-confirm', compact('token'))->header('Referrer-Policy', 'no-referrer');
    }
    public function confirm(Request $request, string $token): RedirectResponse
    {
        $this->throttle($request);
        DB::transaction(function () use ($request, $token) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->auth_version === $request->session()->get('auth_version') && AccountOnboarding::valid($user, 'verify', $token, true), 403);
            $user->email_verified_at = now();
            AccountAccess::invalidate($user, $user->id, 'email_verified', 'staging_onboarding');
        });
        Auth::logout(); $request->session()->invalidate(); $request->session()->regenerateToken();
        return to_route('login')->with('status', 'Email confirmed. Sign in again. This confirms mailbox access only.');
    }
    public function index(Request $request)
    {
        Gate::authorize('manage-directories');
        $data = $request->validate(['search' => ['nullable', 'string', 'max:120'], 'state' => ['nullable', 'in:all,unverified,verified,inactive']]);
        $state = $data['state'] ?? 'unverified'; $search = trim($data['search'] ?? '');
        $users = User::whereIn('role', DirectoryCatalog::STAFF_ROLES)
            ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search) { $q->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%'); }))
            ->when($state === 'unverified', fn ($q) => $q->where('active', true)->whereNull('email_verified_at'))
            ->when($state === 'verified', fn ($q) => $q->where('active', true)->whereNotNull('email_verified_at'))
            ->when($state === 'inactive', fn ($q) => $q->where('active', false))->orderBy('name')->orderBy('id')->paginate(20)->withQueryString();
        $tokens = DB::table('account_onboarding_tokens')->whereIn('user_id', $users->pluck('id'))->where('purpose', 'invite')->get(['user_id', 'created_at', 'expires_at'])->keyBy('user_id');
        return view('onboarding.index', compact('users', 'tokens', 'state', 'search'));
    }
    public function invite(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manage-directories'); $this->throttle($request);
        $data = $request->validate(['record_version' => ['required', 'integer']]);
        try {
            DB::transaction(function () use ($request, $user, $data) {
                // Lock both accounts in stable order, then recheck authority and exact target version.
                $rows = User::whereIn('id', [$request->user()->id, $user->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $target = $rows[$user->id]; $actor = $rows[$request->user()->id];
                abort_unless($actor->auth_version === $request->session()->get('auth_version') && $actor->active && in_array($actor->role, ['admin', 'ceo'], true), 403);
                if ($target->record_version !== (int) $data['record_version']) { throw ValidationException::withMessages(['onboarding' => 'The staff record changed. Reload the list before sending.']); }
                AccountOnboarding::issue($target, 'invite', $actor);
            });
        } catch (TransportExceptionInterface) {
            return back()->withErrors(['onboarding' => 'The invitation could not be prepared. No new link was saved.']);
        }
        return back()->with('status', 'Invitation prepared. The previous invitation was replaced. Check the test inbox; delivery is not guaranteed.');
    }
    public function revoke(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manage-directories');
        DB::transaction(function () use ($request, $user) {
            $rows = User::whereIn('id', [$request->user()->id, $user->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $rows[$request->user()->id]; $target = $rows[$user->id];
            abort_unless($actor->active && in_array($actor->role, ['admin', 'ceo'], true) && $actor->auth_version === $request->session()->get('auth_version') && in_array($target->role, DirectoryCatalog::STAFF_ROLES, true), 403);
            $count = DB::table('account_onboarding_tokens')->where('user_id', $target->id)->where('purpose', 'invite')->delete();
            if ($count) { AccountOnboarding::audit($target, $actor->id, 'invite_revoked'); }
        });
        return back()->with('status', 'Any outstanding invitation has been revoked. Account status and role are unchanged.');
    }
    public function acceptForm(Request $request, int $user, string $token)
    {
        $target = User::find($user);
        abort_unless($target && AccountOnboarding::valid($target, 'invite', $token), 403);
        return response()->view('auth.invitation', ['user' => $user, 'token' => $token])->header('Referrer-Policy', 'no-referrer');
    }
    public function accept(Request $request, int $user, string $token): RedirectResponse
    {
        $this->throttle($request);
        $data = $request->validate(['password' => AccountAccess::passwordRules()]);
        DB::transaction(function () use ($user, $token, $data) {
            // Invitations bind both target and issuer access versions; lock both for acceptance.
            $issuerId = DB::table('account_onboarding_tokens')->where('user_id', $user)->where('purpose', 'invite')->value('issuer_id');
            $rows = User::whereIn('id', array_filter([$user, $issuerId]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $target = $rows->get($user);
            abort_unless((int) DB::table('account_onboarding_tokens')->where('user_id', $user)->where('purpose', 'invite')->value('issuer_id') === (int) $issuerId, 403);
            abort_unless($target && AccountOnboarding::valid($target, 'invite', $token, true), 403);
            $target->email_verified_at = now();
            AccountAccess::setPassword($target, $data['password'], null, 'staff_invitation');
            AccountOnboarding::audit($target, null, 'invite_accepted');
        });
        $request->session()->invalidate(); $request->session()->regenerateToken();
        return to_route('login')->with('status', 'Staff password set and email confirmed. Sign in with your existing staff email.');
    }
}
