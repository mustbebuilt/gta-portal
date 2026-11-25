<?php

namespace Tests\Feature;

use App\Models\AccountSecondFactor;
use App\Models\Apprentice;
use App\Models\ApprenticeSetupDraft68;
use App\Models\Employer;
use App\Models\LearningTask;
use App\Models\PortalPrivacyState;
use App\Models\Programme;
use App\Models\ProgrammeSetupTemplate68;
use App\Models\User;
use App\Support\AuthenticatorCode;
use App\Support\DeliveryPlan;
use App\Support\GuidedSetup68;
use App\Support\PortalPrivacy;
use App\Support\PrivacyRecovery85;
use App\Support\ReportRead85;
use App\Support\Rpl68;
use App\Support\SecondFactor;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuditRepair85Test extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_login_spray_is_limited_across_accounts_without_recording_passwords_or_raw_identifiers(): void
    {
        RateLimiter::clear('login85:ip:'.hash('sha256', '127.0.0.1'));
        for ($i = 0; $i < 31; $i++) {
            $this->post('/login', ['email' => "spray85-$i@example.test", 'password' => 'Fictional secret 85'])->assertSessionHasErrors('email');
        }
        $this->assertDatabaseCount('portal_security_events', 31);
        $this->assertSame('login_throttled', DB::table('portal_security_events')->latest('id')->value('event'));
        $log = json_encode(DB::table('portal_security_events')->get());
        $this->assertStringNotContainsString('Fictional secret', $log);
        $this->assertStringNotContainsString('spray85-', $log);
        $this->assertStringNotContainsString('127.0.0.1', $log);
    }

    public function test_login_account_limit_survives_rotating_ip_addresses(): void
    {
        $email = 'rotating85@example.test';
        RateLimiter::clear('login85:account:'.hash('sha256', $email));
        for ($i = 1; $i <= 11; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$i])->post('/login', ['email' => $email, 'password' => 'Wrong fictional password'])->assertSessionHasErrors('email');
        }
        $this->assertSame('login_throttled', DB::table('portal_security_events')->latest('id')->value('event'));
    }

    public function test_sensitive_views_log_record_identity_without_copying_private_content_and_denied_views_do_not_log_success(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $a = Apprentice::factory()->create();
        $this->actingAs($admin)->get('/apprentices/'.$a->id.'/support')->assertOk();
        $event = DB::table('portal_security_events')->where('event', 'sensitive_access')->first();
        $this->assertNotNull($event);
        $this->assertSame($admin->id, $event->user_id);
        $this->assertStringContainsString((string) $a->id, $event->context);
        $count = DB::table('portal_security_events')->count();
        $learner = User::factory()->create(['role' => 'apprentice']);
        $this->actingAs($learner)->get('/apprentices/'.$a->id.'/support')->assertForbidden();
        $this->assertSame($count, DB::table('portal_security_events')->count());
    }

    public function test_employer_search_preserves_the_draft_version_selection_and_agreement(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $employer = Employer::factory()->create();
        $draft = GuidedSetup68::start($admin, $admin->auth_version);
        $draft->payload = [...$draft->payload, 'employer' => ['id' => (string) $employer->id, 'record_version' => $employer->record_version, 'agreement_verified' => true, 'agreement_ref' => 'Fictional agreed source']];
        $draft->save();
        $draft = $draft->fresh();
        $before = $draft->getAttributes();
        $this->actingAs($admin)->post(route('setup68.save', [$draft, 5]), ['version' => $draft->version, 'employer_search' => 'Fictional'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($before, $draft->fresh()->getAttributes());
        $this->get(route('setup68.show', [$draft, 5]))->assertOk()->assertSee('value="'.$employer->id.'" selected', false);
    }

    public function test_existing_apprentice_resumes_canonical_setup_without_duplicate_records_and_denies_other_roles(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $p = Programme::factory()->create(['standard_code' => 'ST0499', 'version' => '1.3']);
        $source = GuidedSetup68Test::source();
        ProgrammeSetupTemplate68::create(['programme_id' => $p->id, 'template' => $source, 'source_hash' => Rpl68::hash($source), 'active' => true]);
        $employer = Employer::factory()->create();
        $a = Apprentice::factory()->create(['programme_id' => $p->id, 'employer_id' => $employer->id, 'start_date' => '2026-01-01']);
        $a = $a->fresh();
        $before = $a->getAttributes();
        $this->actingAs($admin)->post(route('setup68.resume-existing', $a))->assertRedirect()->assertSessionHasNoErrors();
        $draft = ApprenticeSetupDraft68::sole();
        $this->assertSame($a->id, $draft->apprentice_id);
        $this->assertSame($before, $a->fresh()->getAttributes());
        $this->assertDatabaseCount('apprentices', 1);
        $this->assertArrayNotHasKey('rpl', $draft->payload);
        $this->post(route('setup68.resume-existing', $a))->assertRedirect();
        $this->assertDatabaseCount('apprentice_setup_drafts68', 1);
        $this->get(route('setup68.show', [$draft, 7]))->assertOk()->assertSee('Functional Skills')->assertSee('Confirm no relevant prior learning');
        foreach (['tutor', 'apprentice', 'employer'] as $role) {
            $u = User::factory()->create(['role' => $role]);
            $this->actingAs($u)->post(route('setup68.resume-existing', $a))->assertForbidden();
        }
    }

    public function test_privacy_recovery_is_explicit_preserves_controls_and_requires_trusted_metadata(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $state = PortalPrivacyState::findOrFail(1);
        $fields = PortalPrivacy::fields($state);
        DB::table('portal_privacy_state')->where('id', 1)->update(['proof' => 'broken encrypted proof']);
        $state->refresh();
        $this->assertFalse(PortalPrivacy::intact($state));
        $this->artisan('gta:privacy-state-check')->assertFailed();
        $this->artisan('gta:privacy-state-reseal')->assertSuccessful();
        $this->assertFalse(PortalPrivacy::intact($state->fresh()));
        $this->artisan('gta:privacy-state-reseal', ['--apply' => true, '--actor' => $admin->id, '--expected-state-hash' => str_repeat('a', 64), '--reason' => 'Checked against fictional backup'])->assertFailed();
        $this->artisan('gta:privacy-state-reseal', ['--apply' => true, '--actor' => $admin->id, '--expected-state-hash' => PrivacyRecovery85::stateHash($state), '--reason' => 'Checked against fictional backup'])->assertSuccessful();
        $state->refresh();
        $this->assertTrue(PortalPrivacy::intact($state));
        $this->assertSame($fields['revision'] + 1, $state->revision);
        $this->assertSame($fields['enabled'], $state->enabled);
        $this->assertSame($fields['current_notice_id'], $state->current_notice_id);
        $this->assertDatabaseHas('portal_privacy_events', ['event' => 'state_resealed', 'actor_id' => $admin->id]);
    }

    public function test_privacy_recovery_refuses_enabled_gate_without_published_notice(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        DB::table('portal_privacy_state')->where('id', 1)->update(['proof' => 'broken', 'enabled' => true]);
        $state = PortalPrivacyState::findOrFail(1);
        $this->artisan('gta:privacy-state-reseal', ['--apply' => true, '--actor' => $admin->id, '--expected-state-hash' => PrivacyRecovery85::stateHash($state), '--reason' => 'Checked against fictional backup'])->assertFailed();
        $this->assertFalse(PortalPrivacy::intact($state->fresh()));
    }

    public function test_dashboard_supports_250_authorised_records_and_still_scopes_tutor_data(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tutor = User::factory()->create(['role' => 'tutor']);
        Apprentice::factory()->count(249)->create();
        Apprentice::factory()->create(['tutor_id' => $tutor->id]);
        DB::enableQueryLog();
        $this->actingAs($admin)->get('/progress-dashboard/export/json')->assertOk()->assertJsonPath('record_count', 250);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThan(3000, $queries, 'Batch lookups must keep a 250-record report below the former ~6,250-query pattern.');
        if (getenv('GTA_AUDIT85_QUERY_REPORT')) {
            file_put_contents(getenv('GTA_AUDIT85_QUERY_REPORT'), json_encode(['records' => 250, 'queries' => $queries, 'fixture' => 'Minimal fictional records; no signed/imported history; not a hosted load test']));
        }
        $this->actingAs($tutor)->get('/progress-dashboard/export/json')->assertOk()->assertJsonPath('record_count', 1);
    }

    public function test_report_cache_is_scoped_to_one_read_and_cannot_hide_later_changes(): void
    {
        $a = Apprentice::factory()->create();
        $count = 0;
        ReportRead85::run(collect([$a]), function () use (&$count) {
            $this->assertSame(1, ReportRead85::remember('test', function () use (&$count) {
                return ++$count;
            }));
            $this->assertSame(1, ReportRead85::remember('test', function () use (&$count) {
                return ++$count;
            }));
        });
        $this->assertSame(2, ReportRead85::remember('test', function () use (&$count) {
            return ++$count;
        }));
    }

    public function test_unprivileged_marker_posts_cannot_probe_existing_ids_through_validation(): void
    {
        $user = User::factory()->create(['role' => 'apprentice']);
        $a = Apprentice::factory()->create();
        foreach ([$a->id, 999999] as $id) {
            $this->actingAs($user)->post('/support-register/markers', ['apprentice_id' => $id])->assertForbidden();
        }
    }

    public function test_required_mfa_redirects_unenrolled_staff_to_setup_and_cannot_be_bypassed_by_pilot_cap(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
        config(['second_factor.required_for_support' => true, 'second_factor.enrolment_enabled' => false, 'second_factor.pilot_emails' => []]);
        $this->assertTrue(SecondFactor::mayEnrol($admin));
        $this->actingAs($admin)->get('/apprentices')->assertRedirect(route('second-factor.settings'));
        $this->get('/account/two-step')->assertOk();
        $apprentice = User::factory()->create(['role' => 'apprentice']);
        $this->assertFalse(SecondFactor::policyRequired($apprentice));
    }

    public function test_existing_direct_record_can_complete_setup_and_gain_a_real_approved_plan_without_duplication(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $p = Programme::factory()->create(['standard_code' => 'ST0499', 'version' => '1.3']);
        $source = GuidedSetup68Test::source();
        ProgrammeSetupTemplate68::create(['programme_id' => $p->id, 'template' => $source, 'source_hash' => Rpl68::hash($source), 'active' => true]);
        $employer = Employer::factory()->create();
        $a = Apprentice::factory()->create(['programme_id' => $p->id, 'employer_id' => $employer->id, 'start_date' => '2026-01-01']);
        $this->actingAs($admin)->post(route('setup68.resume-existing', $a))->assertRedirect();
        $draft = ApprenticeSetupDraft68::sole();
        $this->post(route('setup68.save', [$draft, 4]), ['version' => $draft->version, 'data' => ['mode' => 'none', 'remaining_confirmed' => true]])->assertRedirect()->assertSessionHasNoErrors();
        $draft->refresh();
        $this->post(route('setup68.confirm', $draft), ['version' => $draft->version, 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('apprentices', 1);
        $this->assertSame('Complete', $draft->fresh()->state);
        $plan = DeliveryPlan::approved($a->fresh());
        $this->assertNotNull($plan);
        $this->assertEquals(600, $plan['details']['target_hours']);
        $this->get(route('reviews.create', $a))->assertOk()->assertDontSee('A current approved training plan is needed');
    }

    public function test_old_task_history_without_tutor_or_date_remains_readable(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $a = Apprentice::factory()->create();
        $task = LearningTask::factory()->create(['apprentice_id' => $a->id]);
        $task->submissions = [['version' => 1, 'answers' => [], 'decision' => ['status' => 'Approved', 'feedback' => 'Fictional legacy decision']]];
        $task->save();
        $this->actingAs($admin)->get(route('tasks.show', $task))->assertOk()->assertSee('Tutor not recorded')->assertSee('Date not recorded')->assertSee('Fictional legacy decision');
    }

    public function test_privacy_reseal_rolls_back_if_the_recovery_audit_cannot_be_written(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        DB::table('portal_privacy_state')->where('id', 1)->update(['proof' => 'broken']);
        $state = PortalPrivacyState::findOrFail(1);
        $before = $state->getAttributes();
        DB::listen(function ($query) {
            if (str_starts_with(strtolower($query->sql), 'insert into "portal_privacy_events"')) {
                throw new \RuntimeException('Fictional audit failure');
            }
        });
        try {
            PrivacyRecovery85::reseal($admin->id, PrivacyRecovery85::stateHash($state), 'Checked against fictional backup');
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Fictional audit failure', $e->getMessage());
        }
        $this->assertSame($before, $state->fresh()->getAttributes());
    }

    public function test_required_mfa_cannot_be_disabled_even_with_a_valid_code(): void
    {
        $password = 'Fictional required-factor password';
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $admin = User::factory()->create(['role' => 'admin', 'password' => $password]);
        AccountSecondFactor::create(['user_id' => $admin->id, 'secret' => Crypt::encryptString($secret), 'confirmed_at' => now(), 'recovery_hashes' => []]);
        config(['second_factor.required_for_support' => true]);
        $this->actingAs($admin)->withSession(['mfa_version' => $admin->auth_version])->post('/account/two-step', [
            'action' => 'disable', 'current_password' => $password, 'code' => AuthenticatorCode::at($secret, now()->timestamp),
        ])->assertForbidden();
        $this->assertDatabaseHas('account_second_factors', ['user_id' => $admin->id]);
    }

    public function test_unused_webhook_is_not_registered_and_security_headers_preserve_phone_camera(): void
    {
        $this->post('/resend/webhook',[])->assertNotFound();
        $this->get('/login')->assertOk()->assertHeader('Permissions-Policy','camera=(self), microphone=(), geolocation=()')
            ->assertHeader('Content-Security-Policy-Report-Only',"default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
    }
}
