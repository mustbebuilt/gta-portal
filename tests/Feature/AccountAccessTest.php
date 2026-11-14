<?php

namespace Tests\Feature;

use App\Models\ExecutiveAccessSession;
use App\Models\User;
use App\Support\AccountAccess;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AccountAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const NEW_PASSWORD = 'Fictional-long-passphrase-13!';

    private function actor(string $role = 'tutor'): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->save();

        return $u;
    }

    private function enableRecovery(): void
    {
        config(['account_access.recovery_enabled' => true, 'mail.default' => 'array']);
        Notification::fake();
    }

    private function resetData(User $u, string $token, array $extra = []): array
    {
        return [...['email' => $u->email, 'token' => $token, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD], ...$extra];
    }

    public function test_signed_in_password_change_preserves_role_and_signs_out_without_exposing_secrets(): void
    {
        $u = $this->actor();
        $oldRemember = $u->remember_token;
        Password::broker()->createToken($u);
        $this->actingAs($u)->get('/account/password')->assertOk();
        $this->post('/account/password', ['current_password' => 'password', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD, 'role' => 'ceo', 'active' => 0, 'auth_version' => 999])->assertRedirect('/login')->assertSessionHasNoErrors();
        $this->assertGuest();
        $u = $u->fresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $u->password));
        $this->assertSame('tutor', $u->role);
        $this->assertTrue($u->active);
        $this->assertSame(2, $u->auth_version);
        $this->assertNotSame($oldRemember, $u->remember_token);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $events = json_encode(DB::table('account_access_events')->get());
        $this->assertStringNotContainsString(self::NEW_PASSWORD, $events);
        $this->assertStringNotContainsString($u->password, $events);
    }

    public function test_wrong_current_password_short_password_and_confirmation_mismatch_change_nothing(): void
    {
        $u = $this->actor();
        $old = $u->fresh()->getAttributes();
        $this->actingAs($u)->post('/account/password', ['current_password' => 'wrong', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])->assertSessionHasErrors('current_password');
        $this->post('/account/password', ['current_password' => 'password', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post('/account/password', ['current_password' => 'password', 'password' => self::NEW_PASSWORD, 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $this->assertSame($old, $u->fresh()->getAttributes());
        $this->assertDatabaseCount('account_access_events', 0);
    }

    public function test_a_stale_or_pre_upgrade_session_must_sign_in_again(): void
    {
        $u = $this->actor();
        $this->actingAs($u)->withSession(['auth_version' => null])->get('/account/password')->assertRedirect('/login');
        $this->assertGuest();
        $this->actingAs($u);
        DB::table('users')->where('id', $u->id)->update(['auth_version' => 2]);
        $this->get('/account/password')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_real_sign_in_binds_current_security_version_and_old_password_stops_working(): void
    {
        $u = $this->actor();
        DB::transaction(fn () => AccountAccess::setPassword($u, self::NEW_PASSWORD, null, 'local_command'));
        $this->post('/login', ['email' => $u->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $u->email, 'password' => self::NEW_PASSWORD])->assertSessionHasNoErrors()->assertSessionHas('auth_version', 2);
        $this->get('/account/password')->assertOk();
    }

    public function test_recovery_is_disabled_by_default_and_never_returns_a_reset_link(): void
    {
        Notification::fake();
        $u = $this->actor();
        $this->get('/forgot-password')->assertOk()->assertSee('not enabled')->assertDontSee('Request reset instructions');
        $this->post('/forgot-password', ['email' => $u->email])->assertRedirect('/forgot-password')->assertSessionHasNoErrors();
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Notification::assertNothingSent();
    }

    public function test_sink_request_hashes_the_token_and_uses_the_trusted_application_url(): void
    {
        $this->enableRecovery();
        config(['app.url' => 'https://trusted.example.test']);
        $u = $this->actor();
        $this->withHeaders(['Host' => 'attacker.example.test'])->post('/forgot-password', ['email' => strtoupper($u->email)])->assertSessionHasNoErrors();
        Notification::assertSentTo($u, ResetPassword::class, function ($notification) use ($u): bool {
            $stored = DB::table('password_reset_tokens')->where('email', $u->email)->value('token');
            $this->assertNotSame($notification->token, $stored);
            $this->assertTrue(Hash::check($notification->token, $stored));
            $url = $notification->toMail($u)->actionUrl;
            $this->assertStringStartsWith('https://trusted.example.test/', $url);
            $this->assertStringNotContainsString('attacker.example.test', $url);

            return true;
        });
        $this->assertDatabaseCount('account_access_events', 0);
    }

    #[TestWith(['inactive'])] #[TestWith(['unverified'])] #[TestWith(['absent'])]
    public function test_ineligible_accounts_receive_the_same_generic_request_response_without_tokens(string $case): void
    {
        $this->enableRecovery();
        $u = $this->actor();
        if ($case === 'inactive') {
            $u->active = false;
        } elseif ($case === 'unverified') {
            $u->email_verified_at = null;
        }
        $u->save();
        $email = $case === 'absent' ? 'absent@example.test' : $u->email;
        $this->post('/forgot-password', ['email' => $email])->assertSessionHas('status', 'If the account is eligible and recovery is enabled, reset instructions are prepared. Otherwise contact your GTA administrator.');
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Notification::assertNothingSent();
    }

    public function test_token_is_single_use_and_reset_preserves_role_and_invalidates_old_sessions(): void
    {
        $this->enableRecovery();
        $u = $this->actor('ceo');
        $token = Password::broker()->createToken($u);
        $this->post('/reset-password', $this->resetData($u, $token, ['role' => 'admin', 'active' => 0]))->assertRedirect('/login')->assertSessionHasNoErrors();
        $this->assertSame('ceo', $u->fresh()->role);
        $this->assertTrue($u->fresh()->active);
        $this->assertSame(2, $u->fresh()->auth_version);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $u->fresh()->password));
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->post('/reset-password', $this->resetData($u, $token))->assertSessionHasErrors('email');
        $this->assertDatabaseCount('account_access_events', 1);
        $this->actingAs($u)->get('/account/password')->assertRedirect('/login');
    }

    public function test_expired_token_wrong_token_and_token_for_another_account_cannot_reset_passwords(): void
    {
        $this->enableRecovery();
        $u = $this->actor();
        $other = $this->actor();
        $old = $u->password;
        $token = Password::broker()->createToken($u);
        $this->post('/reset-password', $this->resetData($other, $token))->assertSessionHasErrors('email');
        $this->post('/reset-password', $this->resetData($u, 'incorrect-token'))->assertSessionHasErrors('email');
        $this->travel(61)->minutes();
        $this->post('/reset-password', $this->resetData($u, $token))->assertSessionHasErrors('email');
        $this->assertSame($old, $u->fresh()->password);
        $this->assertDatabaseCount('account_access_events', 0);
    }

    public function test_production_or_a_non_sink_mailer_cannot_enable_this_local_recovery_flow(): void
    {
        $this->enableRecovery();
        $u = $this->actor();
        config(['mail.default' => 'smtp']);
        $this->post('/forgot-password', ['email' => $u->email])->assertSessionHasNoErrors();
        config(['mail.default' => 'array']);
        $this->app->instance('env', 'production');
        $this->get('/forgot-password')->assertStatus(503);
        $this->assertFalse(AccountAccess::recoveryAvailable());
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Notification::assertNothingSent();
    }

    public function test_request_throttling_and_broker_throttling_do_not_create_duplicate_notifications(): void
    {
        $this->enableRecovery();
        $u = $this->actor();
        $this->post('/forgot-password', ['email' => $u->email])->assertSessionHasNoErrors();
        $this->post('/forgot-password', ['email' => $u->email])->assertSessionHasNoErrors();
        Notification::assertSentToTimes($u, ResetPassword::class, 1);
        for ($i = 0; $i < 3; $i++) {
            $this->post('/forgot-password', ['email' => 'absent-'.$i.'@example.test'])->assertSessionHasNoErrors();
        }
        $this->post('/forgot-password', ['email' => $u->email])->assertSessionHasErrors('email');
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public function test_failed_password_audit_rolls_back_password_version_and_token_consumption(): void
    {
        $this->enableRecovery();
        $u = $this->actor();
        $token = Password::broker()->createToken($u);
        $old = $u->fresh()->getAttributes();
        \Tests\Support\AuditFault::install('reject_account_event','account_access_events','Fictional audit failure');
        $this->post('/reset-password', $this->resetData($u, $token))->assertStatus(500);
        $this->assertSame($old, $u->fresh()->getAttributes());
        $this->assertTrue(Password::broker()->tokenExists($u, $token));
        $this->assertDatabaseCount('account_access_events', 0);
    }

    public function test_password_reset_ends_all_outstanding_executive_overrides(): void
    {
        $this->enableRecovery();
        $u = $this->actor('ceo');
        ExecutiveAccessSession::factory()->count(2)->create(['user_id' => $u->id]);
        $token = Password::broker()->createToken($u);
        $this->post('/reset-password', $this->resetData($u, $token))->assertSessionHasNoErrors();
        $this->assertSame(0, ExecutiveAccessSession::whereNull('ended_at')->count());
        $this->assertSame(2, DB::table('executive_access_events')->where('action', 'Account access changed')->count());
    }

    public function test_staff_email_change_clears_verification_and_old_reset_token_without_granting_privileges(): void
    {
        $admin = $this->actor('admin');
        $u = $this->actor();
        Password::broker()->createToken($u);
        $this->actingAs($admin)->put('/directories/staff/'.$u->id, ['record_version' => 1, 'name' => $u->name, 'email' => 'changed@example.test', 'role' => 'tutor', 'active' => 1])->assertSessionHasNoErrors();
        $this->assertNull($u->fresh()->email_verified_at);
        $this->assertSame(2, $u->fresh()->auth_version);
        $this->assertSame('tutor', $u->fresh()->role);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertSame($admin->id, DB::table('account_access_events')->value('actor_id'));
    }

    public function test_deactivation_and_reactivation_do_not_restore_an_old_login(): void
    {
        $admin = $this->actor('admin');
        $u = $this->actor();
        $data = ['record_version' => 1, 'name' => $u->name, 'email' => $u->email, 'role' => 'tutor', 'active' => 0];
        $this->actingAs($admin)->put('/directories/staff/'.$u->id, $data)->assertSessionHasNoErrors();
        $this->put('/directories/staff/'.$u->id, [...$data, 'record_version' => 2, 'active' => 1])->assertSessionHasNoErrors();
        $this->assertSame(3, $u->fresh()->auth_version);
        $this->actingAs($u)->get('/account/password')->assertRedirect('/login');
    }

    public function test_reset_tokens_and_passwords_are_not_flashed_back_as_old_input(): void
    {
        $this->enableRecovery();
        $u = $this->actor();
        $this->post('/reset-password', $this->resetData($u, 'fake-token'))->assertSessionHasErrors('email');
        $this->assertNull(session()->getOldInput('token'));
        $this->assertNull(session()->getOldInput('password'));
        $this->assertNull(session()->getOldInput('password_confirmation'));
    }

    public function test_array_password_and_oversized_multibyte_password_are_rejected(): void
    {
        $u = $this->actor();
        $this->actingAs($u)->post('/account/password', ['current_password' => 'password', 'password' => ['bad'], 'password_confirmation' => ['bad']])->assertSessionHasErrors('password');
        $long = str_repeat('é', 40);
        $this->post('/account/password', ['current_password' => 'password', 'password' => $long, 'password_confirmation' => $long])->assertSessionHasErrors('password');
        $this->assertSame(1, $u->fresh()->auth_version);
    }

    public function test_ambiguous_case_only_email_accounts_are_not_silently_selected_for_recovery(): void
    {
        $this->enableRecovery();
        if(in_array(DB::connection()->getDriverName(),['mysql','mariadb'],true)){
            // Legacy case-sensitive schema fixture. Current CI collation normally prevents this ambiguity.
            DB::statement('ALTER TABLE users MODIFY email VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
        }
        User::factory()->create(['email' => 'Mixed@example.test']);
        User::factory()->create(['email' => 'mixed@example.test']);
        $this->post('/forgot-password', ['email' => 'MIXED@example.test'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Notification::assertNothingSent();
    }

    public function test_a_previously_issued_token_cannot_reset_an_account_after_deactivation(): void
    {
        $this->enableRecovery();
        $u = $this->actor();
        $token = Password::broker()->createToken($u);
        $u->active = false;
        $u->save();
        $old = $u->fresh()->password;
        $this->post('/reset-password', $this->resetData($u, $token))->assertSessionHasErrors('email');
        $this->assertSame($old, $u->fresh()->password);
        $this->assertSame(1, $u->fresh()->auth_version);
    }

    public function test_additive_upgrade_retains_existing_account_credentials_and_roles(): void
    {
        $u = $this->actor('ceo');
        Schema::drop('account_access_events');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('auth_version'));
        $before = (array) DB::table('users')->where('id', $u->id)->first();
        $migration = require database_path('migrations/2026_11_13_100000_add_account_access_controls.php');
        $migration->up();
        $after = (array) DB::table('users')->where('id', $u->id)->first();
        $this->assertSame(1, $after['auth_version']);
        unset($after['auth_version']);
        $this->assertSame($before, $after);
    }

    public function test_guest_cannot_change_password_and_posted_account_id_cannot_target_another_user(): void
    {
        $this->get('/account/password')->assertRedirect('/login');
        $u = $this->actor();
        $other = $this->actor();
        $otherHash = $other->password;
        $this->actingAs($u)->post('/account/password', ['current_password' => 'password', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD, 'user_id' => $other->id])->assertSessionHasNoErrors();
        $this->assertSame($otherHash, $other->fresh()->password);
    }
}
