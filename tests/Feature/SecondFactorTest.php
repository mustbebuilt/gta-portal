<?php

namespace Tests\Feature;

use App\Models\AccountSecondFactor;
use App\Models\User;
use App\Support\AccountAccess;
use App\Support\AuthenticatorCode;
use App\Support\SecondFactor;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SecondFactorTest extends TestCase
{
    use LazilyRefreshDatabase;
    private const PASSWORD = 'Fictional-strong-passphrase-09';
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private function user(string $role = 'tutor'): User
    {
        return User::factory()->create(['role' => $role, 'password' => self::PASSWORD]);
    }

    private function pilot(User $user): void
    {
        config(['second_factor.enrolment_enabled' => true, 'second_factor.pilot_emails' => [strtolower($user->email)]]);
    }

    private function confirmed(User $user): AccountSecondFactor
    {
        return AccountSecondFactor::create(['user_id' => $user->id, 'secret' => Crypt::encryptString(self::SECRET),
            'confirmed_at' => now(), 'recovery_hashes' => []]);
    }

    private function signOut(): void
    {
        Auth::logout();
        $this->flushSession();
    }

    private function password(User $user)
    {
        return $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);
    }

    private function code(int $advance = 0): string
    {
        return AuthenticatorCode::at(self::SECRET, now()->timestamp + $advance);
    }

    public static function vectors(): array
    {
        return [[59, '94287082'], [1111111109, '07081804'], [1111111111, '14050471'],
            [1234567890, '89005924'], [2000000000, '69279037'], [20000000000, '65353130']];
    }

    #[DataProvider('vectors')]
    public function test_rfc_6238_sha1_vectors(int $timestamp, string $expected): void
    {
        $this->assertSame($expected, AuthenticatorCode::at(self::SECRET, $timestamp, 8));
        $this->assertSame(substr($expected, -6), AuthenticatorCode::at(self::SECRET, $timestamp));
    }

    public function test_default_enrolment_is_off_and_existing_password_login_is_unchanged(): void
    {
        $user = $this->user();
        $this->assertFalse(SecondFactor::mayEnrol($user));
        $this->password($user)->assertRedirect('/apprentices');
        $this->assertAuthenticatedAs($user);
        $this->get('/account/two-step')->assertForbidden();
    }

    public function test_enrolment_requires_verified_active_staff_exact_allowlist_and_valid_config(): void
    {
        $user = $this->user();$this->pilot($user);$this->assertTrue(SecondFactor::mayEnrol($user));
        foreach (['apprentice', 'employer', 'unassigned'] as $role) {
            $user->role = $role;$this->assertFalse(SecondFactor::mayEnrol($user));
        }
        $user->role = 'tutor';$user->email_verified_at = null;$this->assertFalse(SecondFactor::mayEnrol($user));
        $user->email_verified_at = now();$user->active = false;$this->assertFalse(SecondFactor::mayEnrol($user));
        $user->active = true;
        foreach ([[], [$user->email, $user->email], ['not-an-email'], 'invalid'] as $emails) {
            config(['second_factor.pilot_emails' => $emails]);$this->assertFalse(SecondFactor::mayEnrol($user));
        }
    }

    public function test_setup_requires_current_password_and_stores_encrypted_secret_only(): void
    {
        $user = $this->user();$this->pilot($user);$this->actingAs($user);
        $this->post('/account/two-step', ['action' => 'start', 'current_password' => 'wrong'])->assertSessionHasErrors('code');
        $this->assertDatabaseCount('account_second_factors', 0);
        $this->post('/account/two-step', ['action' => 'start', 'current_password' => self::PASSWORD])->assertRedirect('/account/two-step');
        $factor = AccountSecondFactor::findOrFail($user->id);$secret = Crypt::decryptString($factor->secret);
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertStringNotContainsString($secret, $factor->secret);
        $this->assertArrayNotHasKey('secret', $factor->toArray());
        $this->assertArrayNotHasKey('setup_binding', $factor->toArray());
        $this->assertFalse(SecondFactor::required($user));
        $this->get('/account/two-step')->assertOk()->assertSee($secret)->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_pending_setup_is_bound_to_starting_session_and_expires(): void
    {
        $user = $this->user();$this->pilot($user);$this->actingAs($user);
        $this->post('/account/two-step', ['action' => 'start', 'current_password' => self::PASSWORD]);
        $factor = AccountSecondFactor::findOrFail($user->id);$secret = Crypt::decryptString($factor->secret);
        $binding = session('mfa_setup_binding');
        $this->withSession(['mfa_setup_binding' => 'OTHER-SESSION'])->get('/account/two-step')->assertDontSee($secret);
        $this->post('/account/two-step', ['action' => 'confirm', 'current_password' => self::PASSWORD, 'code' => AuthenticatorCode::at($secret, now()->timestamp)])->assertSessionHasErrors('code');
        $this->withSession(['mfa_setup_binding' => $binding]);$this->travel(16)->minutes();
        $this->get('/account/two-step')->assertDontSee($secret);
        $this->post('/account/two-step', ['action' => 'confirm', 'current_password' => self::PASSWORD, 'code' => AuthenticatorCode::at($secret, now()->timestamp)])->assertSessionHasErrors('code');
        $this->assertFalse(SecondFactor::required($user));
    }

    public function test_confirmation_invalidates_other_sessions_and_shows_ten_recovery_codes_once(): void
    {
        $user = $this->user();$this->pilot($user);$this->actingAs($user);
        $this->post('/account/two-step', ['action' => 'start', 'current_password' => self::PASSWORD]);
        $secret = Crypt::decryptString(AccountSecondFactor::findOrFail($user->id)->secret);
        $this->post('/account/two-step', ['action' => 'confirm', 'current_password' => self::PASSWORD, 'code' => AuthenticatorCode::at($secret, now()->timestamp)])
            ->assertRedirect('/account/two-step')->assertSessionHasNoErrors();
        $codes = session('mfa_recovery_codes.codes');$this->assertCount(10, $codes);$this->assertCount(10, array_unique($codes));
        $this->assertSame(2, $user->fresh()->auth_version);
        $factor = AccountSecondFactor::findOrFail($user->id);$this->assertNotNull($factor->confirmed_at);
        $this->assertNull($factor->setup_binding);$this->assertStringNotContainsString($codes[0], $factor->getRawOriginal('recovery_hashes'));
        $this->get('/account/two-step')->assertOk()->assertSee($codes[0])->assertDontSee($secret);
        $this->get('/account/two-step')->assertDontSee($codes[0]);
        $this->withSession(['auth_version' => 1, 'mfa_version' => 1])->get('/help')->assertRedirect('/login');
    }

    public function test_password_alone_creates_no_authenticated_session_and_requires_fresh_code(): void
    {
        $user = $this->user();$factor = $this->confirmed($user);
        $this->password($user)->assertRedirect('/two-step');$this->assertGuest();
        $this->get('/help')->assertRedirect('/login');
        $this->post('/two-step', ['code' => 'wrong'])->assertSessionHasErrors('code');$this->assertGuest();
        $this->post('/two-step', ['code' => $this->code()])->assertRedirect('/help');$this->assertAuthenticatedAs($user);
        $this->get('/help')->assertOk();
        $this->signOut();$this->password($user);$this->post('/two-step', ['code' => $this->code()])->assertSessionHasErrors('code');$this->assertGuest();
        $this->travel(31)->seconds();$this->post('/two-step', ['code' => $this->code()])->assertRedirect('/apprentices');
    }

    public function test_single_use_recovery_code_works_with_password_and_cannot_be_reused(): void
    {
        $user = $this->user();$factor = $this->confirmed($user);$codes = SecondFactor::recoveryCodes($factor);
        $this->post('/two-step', ['code' => $codes[0]])->assertSessionHasErrors('code');$this->assertGuest();
        $this->password($user);$this->post('/two-step', ['code' => $codes[0]])->assertRedirect('/apprentices');
        $this->assertCount(9, $factor->fresh()->recovery_hashes);
        $this->signOut();$this->password($user);$this->post('/two-step', ['code' => $codes[0]])->assertSessionHasErrors('code');$this->assertGuest();
    }

    public function test_password_reset_or_auth_version_change_stales_pending_challenge_without_removing_factor(): void
    {
        $user = $this->user();$this->confirmed($user);$this->password($user);
        DB::transaction(fn () => AccountAccess::setPassword($user, 'Another-fictional-passphrase', $user->id, 'recovery'));
        $this->post('/two-step', ['code' => $this->code()])->assertSessionHasErrors('code');$this->assertGuest();
        $this->assertTrue(SecondFactor::required($user));
    }

    public function test_expired_cancelled_and_deactivated_pending_challenges_fail_closed(): void
    {
        $user = $this->user();$this->confirmed($user);$this->password($user);$this->travel(301)->seconds();
        $this->post('/two-step', ['code' => $this->code()])->assertSessionHasErrors('code');$this->assertGuest();
        $this->password($user);$this->post('/two-step/cancel')->assertRedirect('/login');
        $this->post('/two-step', ['code' => $this->code()])->assertSessionHasErrors('code');
        $this->password($user);$user->forceFill(['active' => false])->save();
        $this->post('/two-step', ['code' => $this->code()])->assertSessionHasErrors('code');$this->assertGuest();
    }

    public function test_throttle_survives_new_password_challenges(): void
    {
        $user = $this->user();$this->confirmed($user);
        for ($i = 0; $i < 5; $i++) {
            $this->password($user);$this->post('/two-step', ['code' => 'invalid'])->assertSessionHasErrors('code');
        }
        $this->password($user);$this->post('/two-step', ['code' => $this->code()])->assertSessionHasErrors('code');$this->assertGuest();
        $this->travel(301)->seconds();$this->password($user);$this->post('/two-step', ['code' => $this->code()])->assertRedirect('/apprentices');
    }

    public function test_config_off_does_not_bypass_confirmed_factors_or_password_only_old_sessions(): void
    {
        $user = $this->user();$this->confirmed($user);
        config(['second_factor.enrolment_enabled' => false, 'second_factor.pilot_emails' => []]);
        $this->password($user)->assertRedirect('/two-step');$this->assertGuest();
        $this->actingAs($user)->get('/help')->assertRedirect('/login');$this->assertGuest();
    }

    public function test_settings_changes_require_password_and_factor_even_with_an_authenticated_session(): void
    {
        $user = $this->user();$factor = $this->confirmed($user);$old = SecondFactor::recoveryCodes($factor);
        $this->actingAs($user)->withSession(['mfa_version' => $user->auth_version]);
        $this->post('/account/two-step', ['action' => 'regenerate', 'current_password' => 'wrong', 'code' => $this->code()])->assertSessionHasErrors('code');
        $this->post('/account/two-step', ['action' => 'regenerate', 'current_password' => self::PASSWORD, 'code' => 'invalid'])->assertSessionHasErrors('code');
        $this->post('/account/two-step', ['action' => 'regenerate', 'current_password' => self::PASSWORD, 'code' => $old[0]])->assertRedirect('/account/two-step');
        $new = session('mfa_recovery_codes.codes');$this->assertCount(10, $new);
        $this->assertNotContains(hash('sha256', $old[1]), $factor->fresh()->recovery_hashes);
        $this->post('/account/two-step', ['action' => 'disable', 'current_password' => self::PASSWORD, 'code' => $new[0]])->assertRedirect('/help');
        $this->assertDatabaseCount('account_second_factors', 0);$this->get('/help')->assertOk();
        $this->signOut();$this->password($user)->assertRedirect('/apprentices');
    }

    public function test_corrupted_secret_fails_closed_without_exposing_ciphertext_or_accepting_recovery(): void
    {
        $user = $this->user();$factor = $this->confirmed($user);$codes = SecondFactor::recoveryCodes($factor);
        $factor->secret = 'PRIVATE-CORRUPT-CIPHERTEXT';$factor->save();
        $this->password($user);$this->post('/two-step', ['code' => $codes[0]])->assertSessionHasErrors('code');$this->assertGuest();
        $this->get('/two-step')->assertOk()->assertDontSee('PRIVATE-CORRUPT-CIPHERTEXT');
    }

    public function test_only_neighbouring_unused_steps_are_accepted_and_events_contain_no_credentials(): void
    {
        $this->assertNull(AuthenticatorCode::matchingStep(self::SECRET, $this->code(-120), now()->timestamp, null));
        $this->assertNull(AuthenticatorCode::matchingStep(self::SECRET, $this->code(120), now()->timestamp, null));
        $user = $this->user();$factor = $this->confirmed($user);$code = $this->code();
        $this->password($user);$this->post('/two-step', ['code' => $code]);
        $events = json_encode(DB::table('account_access_events')->get());
        foreach ([self::SECRET, self::PASSWORD, $code, $factor->secret] as $secret) { $this->assertStringNotContainsString($secret, $events); }
        $this->assertDatabaseHas('account_access_events', ['user_id' => $user->id, 'event' => 'mfa_sign_in']);
    }

    public function test_recovery_and_challenge_identity_cannot_be_overridden_in_request_fields(): void
    {
        $first = $this->user();$second = $this->user();
        $this->confirmed($first);$other = $this->confirmed($second);$codes = SecondFactor::recoveryCodes($other);
        $this->password($first);
        $this->post('/two-step', ['code' => $codes[0], 'user_id' => $second->id, 'version' => $second->auth_version])->assertSessionHasErrors('code');
        $this->assertGuest();$this->assertCount(10, $other->fresh()->recovery_hashes);
    }

    public function test_password_only_session_cannot_post_account_changes_for_an_enrolled_user(): void
    {
        $user = $this->user();$this->confirmed($user);$original = $user->password;
        $this->actingAs($user)->post('/account/password', ['current_password' => self::PASSWORD,
            'password' => 'A-new-fictional-password', 'password_confirmation' => 'A-new-fictional-password'])->assertRedirect('/login');
        $this->assertSame($original, $user->fresh()->password);$this->assertGuest();
    }

    public function test_migration_refuses_to_drop_confirmed_factors(): void
    {
        $user = $this->user();$this->confirmed($user);
        $migration = require database_path('migrations/2026_11_14_110000_create_account_second_factors.php');
        try {
            $migration->down();$this->fail('Confirmed factors were dropped.');
        } catch (\LogicException) {
            $this->assertTrue(SecondFactor::required($user));
            $this->assertDatabaseCount('account_second_factors', 1);
        }
    }
}
