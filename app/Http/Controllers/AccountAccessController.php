<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\AccountAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Timebox;
use Illuminate\View\View;

class AccountAccessController extends Controller
{
    private const GENERIC = 'If the account is eligible and recovery is enabled, reset instructions are prepared. Otherwise contact your GTA administrator.';

    private function throttle(string $key): void
    {
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Too many attempts. Please try again later.']);
        }
        RateLimiter::hit($key, 300);
    }

    public function changeForm(): View
    {
        return view('auth.password-change');
    }

    public function change(Request $request): RedirectResponse
    {
        $this->throttle('password-change:'.hash('sha256', $request->user()->id.'|'.$request->ip()));
        $data = $request->validate(['current_password' => ['required', 'string', 'max:1024'], 'password' => AccountAccess::passwordRules()]);
        DB::transaction(function () use ($request, $data): void {
            $u = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($u->active && $u->auth_version === $request->session()->get('auth_version'), 403);
            if (! Hash::check($data['current_password'], $u->password)) {
                throw ValidationException::withMessages(['current_password' => 'The current password was not recognised.']);
            }
            AccountAccess::setPassword($u, $data['password'], $u->id, 'signed_in');
        });
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('login')->with('status', 'Password changed. Sign in again. Older sessions have been invalidated.');
    }

    public function forgot(): View
    {
        return view('auth.password-forgot', ['available' => AccountAccess::recoveryAvailable()]);
    }

    public function requestReset(Request $request): RedirectResponse
    {
        $this->throttle('password-request-ip:'.hash('sha256', $request->ip()));
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254']]);
        if (AccountAccess::recoveryAvailable()) {
            (new Timebox)->call(function () use ($data): void {
                DB::transaction(function () use ($data): void {
                $user = AccountAccess::eligible($data['email']);
                if ($user) {
                    $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                    if ($user->active && $user->email_verified_at) {
                        Password::broker()->sendResetLink(['email' => $user->email, 'active' => true]);
                    }
                }
                });
            }, 250000);
        }

        return to_route('password.request')->with('status', self::GENERIC);
    }

    public function resetForm(Request $request, string $token): View
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254']]);

        return view('auth.password-reset', ['available' => AccountAccess::recoveryAvailable(), 'token' => $token, 'email' => $data['email']]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $this->throttle('password-reset-ip:'.hash('sha256', $request->ip()));
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254'], 'token' => ['required', 'string', 'max:256'], 'password' => AccountAccess::passwordRules()]);
        $status = null;
        if (AccountAccess::recoveryAvailable()) {
            $status = (new Timebox)->call(fn () => DB::transaction(function () use ($data) {
                $u = AccountAccess::eligible($data['email']);
                if (! $u) {
                    return null;
                }
                $u = User::whereKey($u->id)->lockForUpdate()->firstOrFail();
                if (! $u->active || ! $u->email_verified_at) {
                    return null;
                }

                return Password::broker()->reset([...$data, 'email' => $u->email, 'active' => true], function (User $user, string $password): void {
                    AccountAccess::setPassword($user, $password, null, 'recovery');
                });
            }), 250000);
        }
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'The reset link is invalid, expired or unavailable. Request a new link or contact your GTA administrator.']);
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('login')->with('status', 'Password reset. Sign in again. Older sessions have been invalidated.');
    }
}
