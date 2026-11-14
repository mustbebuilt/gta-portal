<?php

namespace App\Support;

use App\Models\ExecutiveAccessSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AccountAccess
{
    public static function recoveryAvailable(): bool
    {
        return config('account_access.recovery_enabled') === true && app()->environment(['local', 'testing']) && config('mail.default') === 'array';
    }

    public static function passwordRules(): array
    {
        return ['bail', 'required', 'string', 'confirmed', PasswordRule::min(12), 'max:64', function ($attribute, $value, $fail): void {
            if (strlen($value) > 72) {
                $fail('The password is too long. Use a shorter passphrase.');
            }
        }];
    }

    public static function eligible(string $email): ?User
    {
        $users = User::whereRaw('LOWER(email) = ?', [strtolower(trim($email))])->limit(2)->get();
        $u = $users->count() === 1 ? $users->first() : null;

        return $u && $u->active && $u->email_verified_at ? $u : null;
    }

    public static function invalidate(User $user, ?int $actor, string $event, string $origin): void
    {
        Password::broker()->deleteToken($user);
        if ($user->isDirty('email')) {
            $old = new User(['email' => $user->getOriginal('email')]);
            Password::broker()->deleteToken($old);
            $user->email_verified_at = null;
        }
        $user->auth_version++;
        $user->remember_token = Str::random(60);
        $user->record_version++;
        $user->save();
        foreach (ExecutiveAccessSession::where('user_id', $user->id)->whereNull('ended_at')->lockForUpdate()->get() as $session) {
            $session->ended_at = now();
            $session->save();
            DB::table('executive_access_events')->insert(['executive_access_session_id' => $session->id, 'action' => 'Account access changed', 'created_at' => now()]);
        }
        DB::table('account_access_events')->insert(['user_id' => $user->id, 'actor_id' => $actor, 'event' => $event, 'origin' => $origin, 'created_at' => now()]);
    }

    public static function setPassword(User $user, string $password, ?int $actor, string $origin): void
    {
        $user->password = Hash::make($password);
        self::invalidate($user, $actor, $origin === 'recovery' ? 'password_reset' : 'password_changed', $origin);
    }
}
