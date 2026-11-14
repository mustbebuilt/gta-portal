<?php
namespace App\Support;
use App\Models\User;
use App\Notifications\AccountOnboardingLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountOnboarding
{
    public static function recipients(): array
    {
        $list = config('onboarding.recipients', []);
        return is_array($list) ? array_values(array_unique(array_map(fn ($v) => strtolower(trim($v)), array_filter($list, fn ($v) => is_string($v) && trim($v) !== '')))) : [];
    }
    public static function errors(): array
    {
        return RecoveryDelivery::configurationErrors(config('onboarding.enabled') === true, self::recipients(), 'staging_onboarding_disabled');
    }
    public static function available(): bool { return app()->environment('staging') && self::errors() === []; }
    public static function eligible(User $user, string $purpose): bool
    {
        return self::available() && $user->active && ! $user->email_verified_at
            && in_array(strtolower(trim($user->email)), self::recipients(), true)
            && User::whereRaw('LOWER(email) = ?', [strtolower(trim($user->email))])->count() === 1
            && ($purpose === 'verify' || ($purpose === 'invite' && in_array($user->role, DirectoryCatalog::STAFF_ROLES, true)));
    }
    public static function audit(User $user, ?int $actor, string $event): void
    {
        DB::table('account_access_events')->insert(['user_id' => $user->id, 'actor_id' => $actor, 'event' => $event, 'origin' => 'staging_onboarding', 'created_at' => now()]);
    }
    // Caller owns the transaction and target-user lock. Send synchronously so rejection rolls back the token.
    public static function issue(User $user, string $purpose, ?User $issuer = null): void
    {
        abort_unless(self::eligible($user, $purpose), 403);
        if ($purpose === 'invite') {
            abort_unless($issuer && $issuer->active && in_array($issuer->role, ['admin', 'ceo'], true), 403);
        }
        $old = DB::table('account_onboarding_tokens')->where('user_id', $user->id)->where('purpose', $purpose)->first();
        if ($old && now()->diffInSeconds(\Illuminate\Support\Carbon::parse($old->created_at), true) < 60) {
            throw ValidationException::withMessages(['onboarding' => 'A link was prepared recently. Wait a minute before sending another.']);
        }
        $token = Str::random(64);
        DB::table('account_onboarding_tokens')->updateOrInsert(['user_id' => $user->id, 'purpose' => $purpose], [
            'token_hash' => hash('sha256', $token), 'auth_version' => $user->auth_version,
            'issuer_id' => $issuer?->id, 'issuer_auth_version' => $issuer?->auth_version,
            'created_at' => now(), 'expires_at' => now()->addMinutes(60),
        ]);
        // Never trust the request Host header to construct an account link.
        $path = $purpose === 'invite' ? route('onboarding.accept', [$user->id, $token], false) : route('account.email.confirm', [$token], false);
        $user->notify(new AccountOnboardingLink($purpose, rtrim(config('app.url'), '/').$path));
        self::audit($user, $issuer?->id ?? $user->id, $purpose.'_link_prepared');
    }
    public static function valid(User $user, string $purpose, string $token, bool $lock = false): bool
    {
        if (! self::eligible($user, $purpose) || strlen($token) !== 64) { return false; }
        $q = DB::table('account_onboarding_tokens')->where('user_id', $user->id)->where('purpose', $purpose);
        $row = ($lock ? $q->lockForUpdate() : $q)->first();
        if (! $row || (int) $row->auth_version !== $user->auth_version || now()->greaterThanOrEqualTo($row->expires_at) || ! hash_equals($row->token_hash, hash('sha256', $token))) { return false; }
        if ($purpose === 'invite') {
            $issuer = User::whereKey($row->issuer_id)->first();
            if (! $issuer || ! $issuer->active || ! in_array($issuer->role, ['admin', 'ceo'], true) || $issuer->auth_version !== (int) $row->issuer_auth_version) { return false; }
        }
        return true;
    }
    public static function inspect(): array
    {
        $errors = self::errors();
        return ['mode' => 'controlled_staging_onboarding', 'result' => $errors ? 'blocked' : 'configuration_checks_only', 'blockers' => $errors, 'pilot_recipient_count' => count(self::recipients()), 'sends_performed' => false, 'writes_performed' => false];
    }
}
