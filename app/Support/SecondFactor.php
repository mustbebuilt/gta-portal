<?php

namespace App\Support;

use App\Models\AccountSecondFactor;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class SecondFactor
{
    public static function required(User $user): bool
    {
        return AccountSecondFactor::whereKey($user->id)->whereNotNull('confirmed_at')->exists();
    }

    public static function mayEnrol(User $user): bool
    {
        $emails = config('second_factor.pilot_emails');
        return config('second_factor.enrolment_enabled') === true
            && (app()->environment(['local', 'testing']) || StagingAccess::errors() === [])
            && is_array($emails) && count($emails) >= 1 && count($emails) <= 10
            && count(array_unique($emails)) === count($emails)
            && collect($emails)->every(fn ($email) => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL))
            && $user->active && $user->email_verified_at !== null
            && in_array($user->role, ['admin', 'ceo', ...DirectoryCatalog::STAFF_ROLES], true)
            && in_array(strtolower($user->email), $emails, true)
            && User::whereRaw('LOWER(email) = ?', [strtolower($user->email)])->count() === 1;
    }

    public static function fail(): never
    {
        throw ValidationException::withMessages(['code' => 'The security check was not accepted. Try a fresh code or start sign-in again.']);
    }

    public static function throttle(Request $request, string $purpose, ?int $id): void
    {
        // Account-wide key prevents retrying a code by rotating IP addresses/sessions.
        foreach (['account:'.($id ?? 'none'), 'ip:'.$request->ip()] as $scope) {
            $key = 'mfa:'.$purpose.':'.hash('sha256', $scope);
            if (RateLimiter::tooManyAttempts($key, 5)) {
                self::fail();
            }
        }
        foreach (['account:'.($id ?? 'none'), 'ip:'.$request->ip()] as $scope) {
            RateLimiter::hit('mfa:'.$purpose.':'.hash('sha256', $scope), 300);
        }
    }

    public static function begin(Request $request, User $user): void
    {
        $request->session()->regenerate();
        $request->session()->forget(['auth_version', 'mfa_version', 'mfa_recovery_codes']);
        $request->session()->put('mfa_pending', ['id' => $user->id, 'version' => $user->auth_version,
            'password_binding' => hash('sha256', $user->password), 'expires' => now()->timestamp + 300]);
    }

    public static function complete(Request $request, User $user): void
    {
        Auth::login($user, false);
        $request->session()->regenerate();
        $request->session()->forget('mfa_pending');
        $request->session()->put('auth_version', $user->auth_version);
        $request->session()->put('mfa_version', $user->auth_version);
    }

    public static function pending(Request $request): ?array
    {
        $pending = $request->session()->get('mfa_pending');
        return is_array($pending) && is_int($pending['id'] ?? null) && is_int($pending['expires'] ?? null)
            && $pending['expires'] > now()->timestamp ? $pending : null;
    }

    /** Caller holds the user and factor row locks. Consume before granting access. */
    public static function consume(AccountSecondFactor $factor, string $code, bool $recovery = true): bool
    {
        try {
            $step = AuthenticatorCode::matchingStep(Crypt::decryptString($factor->secret), $code, now()->timestamp, $factor->last_step);
        } catch (\Throwable) {
            return false;
        }
        if ($step !== null) {
            $factor->last_step = $step;
            $factor->save();
            return true;
        }
        if ($recovery && preg_match('/^[A-F0-9]{8}(?:-[A-F0-9]{8}){3}$/D', $code)) {
            $hashes = $factor->recovery_hashes ?? [];
            foreach ($hashes as $index => $hash) {
                if (is_string($hash) && hash_equals($hash, hash('sha256', $code))) {
                    unset($hashes[$index]);
                    $factor->recovery_hashes = array_values($hashes);
                    $factor->save();
                    return true;
                }
            }
        }
        return false;
    }

    public static function recoveryCodes(AccountSecondFactor $factor): array
    {
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $codes[] = implode('-', str_split(strtoupper(bin2hex(random_bytes(16))), 8));
        }
        $factor->recovery_hashes = array_map(fn ($code) => hash('sha256', $code), $codes);
        $factor->save();
        return $codes;
    }

    public static function change(Request $request, string $action, string $password, string $code = ''): void
    {
        self::throttle($request, 'settings', $request->user()->id);
        $codes = DB::transaction(function () use ($request, $action, $password, $code) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->active && $user->auth_version === $request->session()->get('auth_version'), 403);
            if (! Hash::check($password, $user->password)) { self::fail(); }
            $factor = AccountSecondFactor::whereKey($user->id)->lockForUpdate()->first();
            if ($action === 'start') {
                abort_unless(self::mayEnrol($user), 403);
                if ($factor?->confirmed_at) { self::fail(); }
                $factor ??= new AccountSecondFactor(['user_id' => $user->id]);
                $factor->secret = Crypt::encryptString(AuthenticatorCode::secret());
                $factor->setup_expires_at = now()->addMinutes(15);
                $binding = bin2hex(random_bytes(32));
                $request->session()->put('mfa_setup_binding', $binding);
                $factor->setup_binding = hash('sha256', $binding);
                $factor->recovery_hashes = [];
                $factor->last_step = null;
                $factor->save();
                self::event($user, 'mfa_setup_started');
                return null;
            }
            if (! $factor) { self::fail(); }
            if ($action === 'confirm') {
                abort_unless(self::mayEnrol($user), 403);
                if ($factor->confirmed_at || ! $factor->setup_expires_at?->isFuture()
                    || ! hash_equals($factor->setup_binding ?? '', hash('sha256', $request->session()->get('mfa_setup_binding', '')))
                    || ! self::consume($factor, $code, false)) { self::fail(); }
                $factor->confirmed_at = now();
                $factor->setup_expires_at = null;
                $factor->setup_binding = null;
                $factor->save();
                $codes = self::recoveryCodes($factor);
            } else {
                if (! $factor->confirmed_at || ! self::consume($factor, $code)) { self::fail(); }
                if ($action === 'disable') {
                    $factor->delete();
                    $codes = null;
                } elseif ($action === 'regenerate') {
                    $codes = self::recoveryCodes($factor);
                } else { self::fail(); }
            }
            AccountAccess::invalidate($user, $user->id, 'mfa_'.$action, 'second_factor');
            return $codes;
        });
        if ($action !== 'start') {
            $user = User::findOrFail($request->user()->id);
            self::complete($request, $user);
        }
        $request->session()->forget('mfa_recovery_codes');
        if ($codes !== null) {
            $request->session()->put('mfa_recovery_codes', ['codes' => $codes, 'expires' => now()->timestamp + 300]);
        }
    }

    public static function event(User $user, string $event): void
    {
        DB::table('account_access_events')->insert(['user_id' => $user->id, 'actor_id' => $user->id,
            'event' => $event, 'origin' => 'second_factor', 'created_at' => now()]);
    }
}
