<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\ApprenticeAlertDelivery as Delivery;
use App\Models\LearningBlockControl;
use App\Models\LearningTask;
use App\Models\ProgrammeOutcome;
use App\Models\ProgressReviewDraft;
use App\Models\SignedReviewRecord;
use App\Models\SmartAction;
use App\Models\TrainingSession;
use App\Models\User;
use App\Support\ApprenticeAlertTransport;
use App\Support\ApprenticeNotificationDelivery as Alerts;
use App\Support\ApprenticeReminderSources;
use App\Support\MobileVerification;
use App\Support\ReviewedImports;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Mail\Message;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Email;
use Tests\Concerns\ConfiguresStaging;
use Tests\TestCase;

class ApprenticeNotificationTest extends TestCase
{
    use ConfiguresStaging,DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-14 09:15:00'));
        Http::preventStrayRequests();
    }

    private function fixture(string $channels = 'sms'): array
    {
        $u = User::factory()->create(['role' => 'apprentice', 'email' => 'apprentice-'.Str::random(6).'@example.test']);
        $admin = User::factory()->create(['role' => 'admin']);
        $a = Apprentice::factory()->create(['user_id' => $u->id]);
        $a->alert_channel = $channels === 'both' ? 'both' : $channels;
        $a->alerts_enabled = true;
        $a->alert_mobile = '+447700900123';
        $a->alert_mobile_verified_at = now();
        $a->save();
        ProgrammeOutcome::factory()->create(['apprentice_id' => $a->id, 'context' => ['status' => 'Active', 'sourceRef' => 'Fictional enrolment reference']]);
        $t = LearningTask::factory()->create(['apprentice_id' => $a->id, 'due_date' => today()->subDay()]);
        $this->configureStaging();
        config(['apprentice_notifications.enabled' => true, 'apprentice_notifications.scheduled' => false, 'apprentice_notifications.email_enabled' => in_array($channels, ['email', 'both'], true), 'apprentice_notifications.sms_enabled' => in_array($channels, ['sms', 'both'], true), 'apprentice_notifications.emails' => [$u->email], 'apprentice_notifications.phones' => ['+447700900123'], 'apprentice_notifications.key' => 'FAKEKEY1', 'apprentice_notifications.secret' => 'FICTIONAL-SECRET', 'apprentice_notifications.from' => 'GTA', 'apprentice_notifications.attempt_limit' => 50, 'mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'smtp', 'scheme' => 'smtps', 'host' => 'smtp.example.test', 'port' => 465, 'timeout' => 10], 'mail.from.address' => 'portal@example.test']);

        return [$u, $admin, $a, $t];
    }

    private function prepared(): Delivery
    {
        $r = Alerts::prepare(250);
        $this->assertGreaterThan(0, $r['prepared']);

        return Delivery::firstOrFail();
    }

    private function accepted(): void
    {
        Http::fake([MobileVerification::ENDPOINT => Http::response(['message-count' => '1', 'messages' => [['status' => '0', 'to' => '447700900123', 'message-id' => 'FAKE-MESSAGE-1']]])]);
    }

    private function mailAccepted(?callable $capture = null): void
    {
        $mailer = Mockery::mock();
        Mail::shouldReceive('purge')->with('smtp')->andReturnNull();
        Mail::shouldReceive('mailer')->with('smtp')->andReturn($mailer);
        $mailer->shouldReceive('raw')->andReturnUsing(function ($text, $callback) use ($capture) {
            $email = (new Email)->from('portal@example.test')->text($text);
            $callback(new Message($email));
            if ($capture) {
                $capture($text, $email);
            }

            return new SentMessage(new \Symfony\Component\Mailer\SentMessage($email, Envelope::create($email)));
        });
    }

    private function stagePost(User $u, string $url, array $data = [])
    {
        return $this->actingAs($u)->withSession(['_token' => 'fictional-csrf'])->post('https://staging.example.test'.$url, [' _unused' => null, '_token' => 'fictional-csrf', ...$data]);
    }

    public function test_all_delivery_and_schedule_switches_default_off_and_inspect_does_not_send(): void
    {
        $this->assertFalse(config('apprentice_notifications.enabled'));
        $this->assertFalse(config('apprentice_notifications.scheduled'));
        $r = Alerts::inspect();
        $this->assertContains('apprentice_alerts_disabled', $r['blockers']);
        $this->assertFalse($r['sends_performed']);
        $this->assertDatabaseCount('apprentice_alert_deliveries', 0);
        Http::assertNothingSent();
    }

    public function test_one_generic_sms_uses_fixed_protocol_and_persisted_outcome(): void
    {
        [$u,$admin,$a] = $this->fixture();
        $d = $this->prepared();
        $this->accepted();
        $r = Alerts::dispatch($d);
        $this->assertSame('Accepted', $r['state']);
        $d->refresh();
        $this->assertNotNull($d->reserved_at);
        $this->assertNotNull($d->completed_at);
        $this->assertSame('FAKE-MESSAGE-1', $d->provider_id);
        $this->assertSame('Draft', LearningTask::first()->status);
        Http::assertSent(fn ($r) => $r->url() === MobileVerification::ENDPOINT && $r['to'] === '447700900123' && $r['client-ref'] === $d->id && $r['text'] === ApprenticeAlertTransport::smsText() && str_starts_with($r->header('Authorization')[0], 'Basic '));
        $this->assertStringNotContainsString('+447700900123', json_encode($d->getAttributes()));
    }

    public function test_prepare_is_deduplicated_and_both_creates_two_independent_channels(): void
    {
        [$u,$admin,$a] = $this->fixture('both');
        Alerts::prepare(250);
        Alerts::prepare(250);
        $this->assertDatabaseCount('apprentice_alert_deliveries', 2);
        $this->assertSame(['email', 'sms'], Delivery::orderBy('channel')->pluck('channel')->all());
        Http::assertNothingSent();
    }

    public function test_opt_out_after_preparation_suppresses_without_any_provider_attempt(): void
    {
        [$u,$admin,$a] = $this->fixture();
        $d = $this->prepared();
        $a->alerts_enabled = false;
        $a->alert_revision++;
        $a->save();
        $this->assertSame('Suppressed', Alerts::dispatch($d)['state']);
        $this->assertNull($d->fresh()->reserved_at);
        Http::assertNothingSent();
    }

    public function test_mobile_change_unverification_and_allowlist_removal_stop_sending(): void
    {
        [$u,$admin,$a] = $this->fixture();
        $d = $this->prepared();
        $a->alert_mobile = '+447700900124';
        $a->alert_mobile_verified_at = null;
        $a->save();
        $this->assertSame('Suppressed', Alerts::dispatch($d)['state']);
        Http::assertNothingSent();
    }

    public function test_email_acceptance_uses_verified_login_address_and_generic_text(): void
    {
        [$u,$admin,$a] = $this->fixture('email');
        $a->email = 'WRONG-PROFILE-CONTACT@example.test';
        $a->save();
        $d = $this->prepared();
        $this->mailAccepted(function ($text, $email) use ($u, $d) {
            $this->assertSame($u->email, $email->getTo()[0]->getAddress());
            $this->assertStringNotContainsString('WRONG-PROFILE-CONTACT', $text);
            $this->assertStringNotContainsString('TEST-', $text);
            $this->assertStringContainsString('/my-next-step', $text);
            $this->assertSame($d->id, $email->getHeaders()->get('X-GTA-Delivery-ID')->getBodyAsString());
        });
        $this->assertSame('Accepted', Alerts::dispatch($d)['state']);
        $this->assertSame(64, strlen($d->fresh()->provider_id));
    }

    public function test_email_and_text_eligibility_are_independent_for_both_preference(): void
    {
        [$u,$admin,$a] = $this->fixture('both');
        $a->alert_mobile_verified_at = null;
        $a->save();
        Alerts::prepare(250);
        $this->assertDatabaseCount('apprentice_alert_deliveries', 1);
        $this->assertSame('email', Delivery::first()->channel);
        $this->assertSame('both', $a->fresh()->alert_channel);
    }

    public function test_staff_employer_unverified_and_unlinked_accounts_are_excluded(): void
    {
        [$u,$admin,$a] = $this->fixture();
        $u->role = 'employer';
        $u->save();
        Alerts::prepare(250);
        $this->assertDatabaseCount('apprentice_alert_deliveries', 0);
        $u->role = 'apprentice';
        $u->email_verified_at = null;
        $u->save();
        Alerts::prepare(250);
        $this->assertDatabaseCount('apprentice_alert_deliveries', 0);
        Http::assertNothingSent();
    }

    public function test_unknown_legacy_preferences_and_other_channel_never_enrol_silently(): void
    {
        [$u,$admin,$a] = $this->fixture();
        $a->alerts_enabled = null;
        $a->alert_channel = null;
        $a->save();
        Alerts::prepare(250);
        $this->assertDatabaseCount('apprentice_alert_deliveries', 0);
        $this->assertNull($a->fresh()->alert_channel);
        $a->alerts_enabled = true;
        $a->alert_channel = 'email';
        $a->save();
        Alerts::prepare(250);
        $this->assertDatabaseCount('apprentice_alert_deliveries', 0);
    }

    public function test_paused_completed_and_unsourced_programmes_do_not_generate_reminders(): void
    {
        [$u,$admin,$a] = $this->fixture();
        $outcome = ProgrammeOutcome::where('apprentice_id', $a->id)->first();
        foreach ([['status' => 'Break in learning', 'sourceRef' => 'x'], ['status' => 'Completed', 'sourceRef' => 'x'], ['status' => 'Active', 'sourceRef' => '']] as $c) {
            $outcome->context = $c;
            $outcome->save();
            Alerts::prepare(250);
            $this->assertDatabaseCount('apprentice_alert_deliveries', 0);
        }
    }

    public function test_task_resolution_reassignment_and_auth_version_changes_invalidate_pending(): void
    {
        [$u,$admin,$a,$t] = $this->fixture();
        $d = $this->prepared();
        $t->status = 'Approved';
        $t->save();
        $this->assertSame('Stale', Alerts::dispatch($d)['state']);
        $t->status = 'Draft';
        $t->save();
        Alerts::prepare(250);
        $u->auth_version++;
        $u->save();
        $this->assertSame('Stale', Alerts::dispatch($d->fresh())['state']);
        Http::assertNothingSent();
    }

    public function test_config_change_cannot_reuse_prepared_source_until_refreshed(): void
    {
        [$u] = $this->fixture();
        $d = $this->prepared();
        config(['apprentice_notifications.from' => 'GTATEST']);
        $this->assertSame('Stale', Alerts::dispatch($d)['state']);
        $r = Alerts::prepare(250);
        $this->assertSame(1, $r['refreshed_unsent']);
        $this->accepted();
        $this->assertSame('Accepted', Alerts::dispatch($d->fresh())['state']);
    }

    public function test_source_builder_excludes_blocked_pending_marking_and_staff_actions(): void
    {
        [$u,$admin,$a,$t] = $this->fixture();
        $t->status = 'Awaiting marking';
        $t->save();
        LearningTask::factory()->create(['apprentice_id' => $a->id, 'block' => 2, 'reference' => 'b2-t3', 'due_date' => today()->subDay()]);
        LearningBlockControl::create(['apprentice_id' => $a->id, 'enabled' => true, 'exceptions' => [], 'history' => []]);
        SmartAction::factory()->create(['apprentice_id' => $a->id, 'owner' => 'Tutor', 'due_date' => today()->subDay()]);
        $p = ApprenticeReminderSources::build($a);
        $this->assertNull($p['source']);
    }

    public function test_referred_work_and_standalone_apprentice_actions_are_combined_once(): void
    {
        [$u,$admin,$a,$t] = $this->fixture();
        $t->status = 'Referred';
        $t->due_date = null;
        $t->save();
        SmartAction::factory()->create(['apprentice_id' => $a->id, 'owner' => 'Apprentice', 'due_date' => today()->subDay()]);
        SmartAction::factory()->create(['apprentice_id' => $a->id, 'learning_task_id' => $t->id, 'owner' => 'Apprentice', 'due_date' => today()->subDay()]);
        $p = ApprenticeReminderSources::build($a);
        $this->assertCount(2, $p['source']['items']);
        $this->assertSame('task_referred', $p['source']['items'][0]['kind']);
    }

    public function test_next_day_training_requires_saved_roster_and_planned_state(): void
    {
        [$u,$admin,$a,$t] = $this->fixture();
        $t->status = 'Approved';
        $t->save();
        TrainingSession::factory()->create(['session_date' => today()->addDay(), 'members' => []]);
        TrainingSession::factory()->create(['session_date' => today()->addDay(), 'members' => [$a->id], 'state' => 'Cancelled']);
        $s = TrainingSession::factory()->create(['session_date' => today()->addDay(), 'members' => [(string) $a->id]]);
        $p = ApprenticeReminderSources::build($a);
        $this->assertCount(1, $p['source']['items']);
        $this->assertSame($s->id, $p['source']['items'][0]['id']);
        $this->assertSame('training_tomorrow', $p['source']['items'][0]['kind']);
    }

    public function test_review_reminder_needs_checked_copy_reference_not_a_draft_or_first_date_guess(): void
    {
        [$u,$admin,$a,$t] = $this->fixture();
        $t->status = 'Approved';
        $t->save();
        $a->start_date = today()->subDays(100);
        $a->save();
        $r = ProgressReviewDraft::factory()->create(['apprentice_id' => $a->id, 'fields' => ['reviewDate' => today()->subDays(90)->toDateString()]]);
        $this->assertNull(ApprenticeReminderSources::build($a)['source']);
        $date = today()->subDays(80)->toDateString();
        $hash = str_repeat('a', 64);
        $s = ['apprentice_id' => $a->id, 'review_id' => $r->id, 'review_version' => 1, 'file_sha256' => $hash, 'fields' => ['reviewDate' => $date]];
        SignedReviewRecord::create(['apprentice_id' => $a->id, 'progress_review_draft_id' => $r->id, 'review_version' => 1, 'meeting_date' => $date, 'snapshot' => $s, 'snapshot_hash' => ReviewedImports::hash($s), 'file' => ['sha256' => $hash], 'prepared_by' => $admin->id, 'checked_by' => $admin->id, 'checked_at' => now(), 'history' => []]);
        $p = ApprenticeReminderSources::build($a);
        $this->assertSame('review_due', $p['source']['items'][0]['kind']);
        $this->assertSame(today()->subDays(10)->toDateString(), $p['source']['items'][0]['date']);
    }

    public function test_private_cases_feedback_answers_and_deadline_reasons_never_enter_source_or_sms(): void
    {
        [$u,$admin,$a,$t] = $this->fixture();
        $t->answers = ['secret' => 'PRIVATE-ANSWER'];
        $t->deadline_history = [['reason' => 'PRIVATE-DEADLINE']];
        $t->submissions = [['decision' => ['feedback' => 'PRIVATE-FEEDBACK']]];
        $t->save();
        $d = $this->prepared();
        $raw = json_encode($d->source).ApprenticeAlertTransport::smsText();
        foreach (['PRIVATE-ANSWER', 'PRIVATE-DEADLINE', 'PRIVATE-FEEDBACK', $u->email, $a->alert_mobile] as $s) {
            $this->assertStringNotContainsString($s, $raw);
        }
    }

    public function test_one_item_batch_does_not_starve_eligible_profile_behind_unlinked_profiles(): void
    {
        Apprentice::factory()->count(25)->create();
        [$u] = $this->fixture();
        $r = Alerts::prepare(1);
        $this->assertSame(1, $r['prepared']);
        $this->assertGreaterThan(20, $r['profiles_checked']);
    }

    public function test_invalid_environment_master_and_channel_switches_stop_sends(): void
    {
        [$u] = $this->fixture();
        $d = $this->prepared();
        config(['apprentice_notifications.enabled' => false]);
        $this->assertSame('Suppressed', Alerts::dispatch($d)['state']);
        $this->assertNull($d->fresh()->reserved_at);
        Http::assertNothingSent();
    }

    public function test_long_origin_redirects_malformed_responses_and_wrong_numbers_are_not_accepted(): void
    {
        [$u] = $this->fixture();
        $d = $this->prepared();
        Http::fake([MobileVerification::ENDPOINT => Http::response(['message-count' => '1', 'messages' => [['status' => '0', 'to' => '447700900999', 'message-id' => 'FAKE']]])]);
        $this->assertSame('Uncertain', Alerts::dispatch($d)['state']);
        $this->assertNotNull($d->fresh()->reserved_at);
        $this->assertSame('Blocked', Alerts::dispatch($d->fresh())['state']);
        Http::assertSentCount(1);
    }

    public function test_explicit_sms_rejection_consumes_attempt_and_cannot_retry_same_day(): void
    {
        [$u] = $this->fixture();
        $d = $this->prepared();
        Http::fake([MobileVerification::ENDPOINT => Http::response(['message-count' => '1', 'messages' => [['status' => '4', 'to' => '447700900123']]])]);
        $this->assertSame('Rejected', Alerts::dispatch($d)['state']);
        Alerts::prepare(250);
        $this->assertSame('Blocked', Alerts::dispatch($d->fresh())['state']);
        Http::assertSentCount(1);
    }

    public function test_transport_exception_records_uncertainty_without_raw_error_or_retry(): void
    {
        [$u] = $this->fixture();
        $d = $this->prepared();
        Http::fake(fn () => throw new \RuntimeException('PRIVATE-PROVIDER-SECRET'));
        $this->assertSame('Uncertain', Alerts::dispatch($d)['state']);
        $this->assertStringNotContainsString('PRIVATE-PROVIDER-SECRET', json_encode($d->fresh()->getAttributes()));
        $this->assertSame('Blocked', Alerts::dispatch($d->fresh())['state']);
    }

    public function test_committed_reservation_survives_post_acceptance_database_failure_and_blocks_replay(): void
    {
        [$u] = $this->fixture();
        $d = $this->prepared();
        $accepted = false;
        DB::listen(function ($query) use (&$accepted) {
            if ($accepted && str_starts_with(strtolower($query->sql), 'insert into') && str_contains($query->sql, 'audit_events')) {
                throw new \RuntimeException('Fictional final audit failure');
            }
        });
        Http::fake(function () use (&$accepted) {
            $accepted = true;

            return Http::response(['message-count' => '1', 'messages' => [['status' => '0', 'to' => '447700900123', 'message-id' => 'FAKE-ACCEPTED']]]);
        });
        $r = Alerts::dispatch($d);
        $accepted = false;
        $this->assertSame('Dispatching', $r['state']);
        $this->assertSame('Dispatching', $d->fresh()->state);
        $this->assertNotNull($d->fresh()->reserved_at);
        $this->assertSame('Blocked', Alerts::dispatch($d->fresh())['state']);
        Http::assertSentCount(1);
    }

    public function test_attempt_budget_is_enforced_before_second_channel_reservation(): void
    {
        [$u] = $this->fixture('both');
        config(['apprentice_notifications.attempt_limit' => 1]);
        Alerts::prepare(250);
        $sms = Delivery::where('channel', 'sms')->first();
        $email = Delivery::where('channel', 'email')->first();
        $this->accepted();
        $this->assertSame('Accepted', Alerts::dispatch($sms)['state']);
        $this->assertSame('Blocked', Alerts::dispatch($email)['state']);
        $this->assertNull($email->fresh()->reserved_at);
    }

    public function test_cycle_expiry_is_not_sent_and_new_day_can_prepare_a_fresh_digest(): void
    {
        [$u] = $this->fixture();
        $d = $this->prepared();
        $this->travel(1)->days();
        $this->assertSame('Stale', Alerts::dispatch($d)['state']);
        $r = Alerts::prepare(250);
        $this->assertSame(1, $r['prepared']);
        $this->assertDatabaseCount('apprentice_alert_deliveries', 2);
    }

    public function test_cancelled_slot_does_not_resurrect_and_reconciliation_never_resets_attempt(): void
    {
        [$u,$admin] = $this->fixture();
        $d = $this->prepared();
        Alerts::cancel($admin, $admin->auth_version, $d, 'Fictional cancellation');
        Alerts::prepare(250);
        $this->assertSame('Cancelled', $d->fresh()->state);
        $this->assertSame('Blocked', Alerts::dispatch($d->fresh())['state']);
        Http::assertNothingSent();
    }

    public function test_reserved_uncertain_row_can_be_reconciled_without_retry(): void
    {
        [$u,$admin] = $this->fixture();
        $d = $this->prepared();
        Http::fake(fn () => Http::response('malformed', 200));
        Alerts::dispatch($d);
        Alerts::reconcile($admin, $admin->auth_version, $d->fresh(), 'Fictional provider records checked');
        $this->assertSame('Uncertain', $d->fresh()->state);
        $this->assertCount(1, $d->fresh()->reconciliation);
        $this->assertSame('Blocked', Alerts::dispatch($d->fresh())['state']);
        Http::assertSentCount(1);
    }

    public function test_management_and_apprentice_pages_preserve_role_scope(): void
    {
        [$u,$admin,$a] = $this->fixture();
        $this->prepared();
        $this->actingAs($admin)->get('https://staging.example.test/apprentice-reminder-delivery?preview=1')->assertOk()->assertSee('Current source preview');
        $this->actingAs($u)->get('https://staging.example.test/my-reminders')->assertOk()->assertSee('Task past its recorded deadline');
        $this->get('https://staging.example.test/apprentice-reminder-delivery')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'tutor']))->get('https://staging.example.test/my-reminders')->assertForbidden();
    }

    public function test_successor_account_cannot_read_predecessor_history_and_seen_does_not_complete_work(): void
    {
        [$u,$admin,$a,$t] = $this->fixture();
        $d = $this->prepared();
        $this->stagePost($u, '/my-reminders/'.$d->id.'/seen')->assertRedirect();
        $this->assertNotNull($d->fresh()->seen_at);
        $this->assertSame('Draft', $t->fresh()->status);
        $next = User::factory()->create(['role' => 'apprentice']);
        $a->user_id = $next->id;
        $a->save();
        $this->actingAs($next)->get('https://staging.example.test/my-reminders')->assertOk()->assertDontSee('Task past its recorded deadline');
        $this->stagePost($next, '/my-reminders/'.$d->id.'/seen')->assertForbidden();
    }

    public function test_private_ops_cancellation_notes_do_not_leak_into_apprentice_inbox(): void
    {
        [$u,$admin] = $this->fixture();
        $d = $this->prepared();
        Alerts::cancel($admin, $admin->auth_version, $d, 'PRIVATE-OPS-NOTE');
        $this->actingAs($u)->get('https://staging.example.test/my-reminders')->assertOk()->assertSee('Cancelled')->assertDontSee('PRIVATE-OPS-NOTE');
    }

    public function test_config_limits_invalid_lists_budget_and_sms_length_fail_closed(): void
    {
        [$u] = $this->fixture();
        config(['apprentice_notifications.attempt_limit' => 501]);
        $this->assertContains('attempt_budget_between_1_and_500_required', Alerts::errors('sms'));
        config(['apprentice_notifications.attempt_limit' => 50, 'app.url' => 'https://'.str_repeat('a', 120).'.example.test']);
        $this->assertContains('ascii_origin_and_single_short_sms_required', Alerts::errors('sms'));
        $this->assertLessThanOrEqual(160, strlen((config(['app.url' => 'https://staging.example.test']) ?? '').ApprenticeAlertTransport::smsText()));
    }

    public function test_unauthorised_actor_and_outer_transaction_cannot_dispatch(): void
    {
        [$u] = $this->fixture();
        $d = $this->prepared();
        try {
            Alerts::dispatch($d, $u, $u->auth_version);
            $this->fail('Learner dispatched');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }$result = DB::transaction(fn () => Alerts::dispatch($d));
        $this->assertSame('Blocked', $result['state']);
        Http::assertNothingSent();
    }

    public function test_schema_round_trip_keeps_preferences_mobile_and_employer_gateway_tables(): void
    {
        [$u] = $this->fixture();
        $m = require database_path('migrations/2026_11_14_240000_create_apprentice_alert_deliveries.php');
        $m->down();
        $this->assertFalse(Schema::hasTable('apprentice_alert_deliveries'));
        $this->assertTrue(Schema::hasTable('employer_portal_actions'));
        $this->assertTrue(Schema::hasTable('gateway_readiness_plans'));
        $this->assertTrue(Schema::hasColumn('apprentices', 'alerts_enabled'));
        $this->assertTrue(Schema::hasTable('apprentice_mobile_challenges'));
        $m->up();
        $this->assertTrue(Schema::hasTable('apprentice_alert_deliveries'));
    }

    public function test_durable_pause_blocks_attempts_without_changing_preferences_or_source(): void
    {
        [$u,$admin,$a,$t] = $this->fixture();
        $d = $this->prepared();
        Alerts::pause($admin, $admin->auth_version, 1, true, 'Fictional maintenance');
        $this->assertTrue(Alerts::control()['paused']);
        $this->assertSame('Blocked', Alerts::dispatch($d)['state']);
        $this->assertNull($d->fresh()->reserved_at);
        $this->assertTrue($a->fresh()->alerts_enabled);
        $this->assertSame('Draft', $t->fresh()->status);
        Alerts::pause($admin, $admin->auth_version, 2, false, 'Fictional resumed');
        $this->accepted();
        $this->assertSame('Accepted', Alerts::dispatch($d)['state']);
    }

    public function test_pause_version_and_role_are_checked_and_default_inspection_is_database_free(): void
    {
        [$u,$admin] = $this->fixture();
        DB::enableQueryLog();
        DB::flushQueryLog();
        Alerts::inspect();
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->stagePost($u, '/apprentice-reminder-control', ['version' => 1, 'paused' => 1, 'note' => 'Forged'])->assertForbidden();
        $this->stagePost($admin, '/apprentice-reminder-control', ['version' => 99, 'paused' => 1, 'note' => 'Stale'])->assertSessionHasErrors('alert');
        $this->assertFalse(Alerts::control()['paused']);
    }

    public function test_missing_global_control_row_fails_closed_before_network(): void
    {
        [$u] = $this->fixture();
        $d = $this->prepared();
        DB::table('apprentice_alert_controls')->delete();
        $this->assertSame('Blocked', Alerts::dispatch($d)['state']);
        $this->assertNull($d->fresh()->reserved_at);
        Http::assertNothingSent();
    }

    public function test_prepare_expires_only_unreserved_previous_cycle_rows(): void
    {
        [$u] = $this->fixture();
        $d = $this->prepared();
        $this->travel(1)->days();
        $r = Alerts::prepare(250);
        $this->assertSame(1, $r['expired_unreserved']);
        $this->assertSame('Stale', $d->fresh()->state);
        $this->assertNull($d->fresh()->reserved_at);
        $this->assertDatabaseCount('apprentice_alert_deliveries', 2);
    }

    public function test_shared_mobile_contact_is_not_sent_twice_in_a_calendar_day(): void
    {
        [$u,$admin,$a] = $this->fixture();
        $d = $this->prepared();
        $this->accepted();
        Alerts::dispatch($d);
        $other = User::factory()->create(['role' => 'apprentice']);
        $b = Apprentice::factory()->create(['user_id' => $other->id]);
        $b->alert_channel = 'sms';
        $b->alerts_enabled = true;
        $b->alert_mobile = '+447700900123';
        $b->alert_mobile_verified_at = now();
        $b->save();
        ProgrammeOutcome::factory()->create(['apprentice_id' => $b->id, 'context' => ['status' => 'Active', 'sourceRef' => 'Fictional active source']]);
        LearningTask::factory()->create(['apprentice_id' => $b->id, 'due_date' => today()]);
        Alerts::prepare(250);
        $otherDelivery = Delivery::where('apprentice_id', $b->id)->firstOrFail();
        $this->assertSame('Blocked', Alerts::dispatch($otherDelivery)['state']);
        $this->assertNull($otherDelivery->fresh()->reserved_at);
        Http::assertSentCount(1);
    }

    public function test_daily_contact_rule_uses_calendar_date_not_exact_24_hour_spacing(): void
    {
        [$u] = $this->fixture();
        $d = $this->prepared();
        $this->accepted();
        Alerts::dispatch($d);
        $this->travelTo(Carbon::parse('2026-11-15 09:14:59'));
        Alerts::prepare(250);
        $next = Delivery::whereDate('cycle_date', '2026-11-15')->firstOrFail();
        $this->assertSame('Accepted', Alerts::dispatch($next)['state']);
        Http::assertSentCount(2);
    }

    public function test_sms_response_matrix_rejects_ambiguous_protocol_outcomes(): void
    {
        $this->fixture();
        $base = ['message-count' => '1', 'messages' => [['status' => '0', 'to' => '447700900123', 'message-id' => 'FAKE']]];
        foreach ([[302, $base], [200, ['message-count' => '2', 'messages' => $base['messages']]], [200, ['message-count' => '1', 'messages' => [['status' => '0', 'to' => '447700900123']]]], [200, 'not-json']] as [$status,$body]) {
            Http::swap(new \Illuminate\Http\Client\Factory);Http::preventStrayRequests();
            Http::fake(fn ($request, $options) => Http::response($body, $status));
            $result = ApprenticeAlertTransport::send('sms', '+447700900123', (string) Str::uuid());
            $this->assertSame('Uncertain', $result['state']);
        }
    }

    public function test_sms_transport_disables_redirects_and_enforces_tls_and_timeouts(): void
    {
        $this->fixture();
        $observed = null;
        Http::fake(function ($request, $options) use (&$observed) {
            $observed = $options;

            return Http::response(['message-count' => '1', 'messages' => [['status' => '0', 'to' => '447700900123', 'message-id' => 'FAKE']]]);
        });
        $result = ApprenticeAlertTransport::send('sms', '+447700900123', (string) Str::uuid());
        $this->assertSame('Accepted', $result['state']);
        $this->assertTrue($observed['verify']);
        $this->assertFalse($observed['allow_redirects']);
        $this->assertSame(10, $observed['timeout']);
        $this->assertSame(5, $observed['connect_timeout']);
    }

    public function test_smtp_without_acceptance_result_is_uncertain_and_never_retried(): void
    {
        $this->fixture('email');
        $d = $this->prepared();
        $mailer = Mockery::mock();
        Mail::shouldReceive('purge')->with('smtp')->andReturnNull();
        Mail::shouldReceive('mailer')->with('smtp')->andReturn($mailer);
        $mailer->shouldReceive('raw')->once()->andReturnNull();
        $this->assertSame('Uncertain', Alerts::dispatch($d)['state']);
        $this->assertSame('Blocked', Alerts::dispatch($d->fresh())['state']);
    }

    public function test_configuration_check_and_preview_commands_do_not_write_or_send(): void
    {
        $this->fixture();
        $this->artisan('gta:apprentice-alerts')->assertExitCode(0);
        $this->artisan('gta:apprentice-alerts', ['--preview' => true])->assertExitCode(0);
        $this->assertDatabaseCount('apprentice_alert_deliveries', 0);
        Http::assertNothingSent();
        $this->artisan('gta:apprentice-alerts', ['--preview' => true, '--send' => true])->assertExitCode(1);
    }

    public function test_no_preference_or_work_completion_changes_from_reading_both_channel_digest(): void
    {
        [$u,$admin,$a,$t] = $this->fixture('both');
        Alerts::prepare(250);
        $d = Delivery::first();
        $this->stagePost($u, '/my-reminders/'.$d->id.'/seen')->assertRedirect();
        $this->assertSame(0, Delivery::whereNull('seen_at')->count());
        $this->assertSame('both', $a->fresh()->alert_channel);
        $this->assertTrue($a->fresh()->alerts_enabled);
        $this->assertSame('Draft', $t->fresh()->status);
    }

    public function test_corrupt_encrypted_mobile_does_not_prevent_eligible_email_preparation(): void
    {
        [$u,$admin,$a] = $this->fixture('both');
        DB::table('apprentices')->where('id', $a->id)->update(['alert_mobile' => 'not-a-valid-ciphertext']);
        $result = Alerts::prepare(250);
        $this->assertSame(1, $result['prepared']);
        $this->assertSame('email', Delivery::firstOrFail()->channel);
        $this->assertSame('encrypted_contact_needs_check', Alerts::eligible($a->fresh(), 'sms')['reason']);
    }

    public function test_batched_sender_scans_past_an_early_contact_hold(): void
    {
        [$u,$admin,$a] = $this->fixture();
        $first = $this->prepared();
        Http::fake(fn($request)=>Http::response(['message-count'=>'1','messages'=>[['status'=>'0','to'=>$request['to'],'message-id'=>'FAKE-FIRST']]]));
        Alerts::dispatch($first);
        $profileIds = [];
        foreach (['+447700900123', '+447700900124'] as $phone) {
            $user = User::factory()->create(['role' => 'apprentice']);
            $b = Apprentice::factory()->create(['user_id' => $user->id]);
            $b->alert_channel = 'sms';
            $b->alerts_enabled = true;
            $b->alert_mobile = $phone;
            $b->alert_mobile_verified_at = now();
            $b->save();
            ProgrammeOutcome::factory()->create(['apprentice_id' => $b->id, 'context' => ['status' => 'Active', 'sourceRef' => 'Fictional checked source']]);
            LearningTask::factory()->create(['apprentice_id' => $b->id, 'due_date' => today()]);
            $profileIds[] = $b->id;
        }
        config(['apprentice_notifications.phones' => ['+447700900123', '+447700900124']]);
        Alerts::prepare(250);
        $held = Delivery::where('apprentice_id', $profileIds[0])->first();
        $eligible = Delivery::where('apprentice_id', $profileIds[1])->first();
        DB::table('apprentice_alert_deliveries')->where('id', $held->id)->update(['id' => '00000000-0000-4000-8000-000000000001']);
        DB::table('apprentice_alert_deliveries')->where('id', $eligible->id)->update(['id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff']);
        Http::fake(fn ($request) => Http::response(['message-count' => '1', 'messages' => [['status' => '0', 'to' => $request['to'], 'message-id' => 'FAKE-NEXT']]]));
        $counts = Alerts::sendPrepared(1);
        $this->assertSame(1, $counts['Blocked']);
        $this->assertSame(1, $counts['Accepted']);
        $this->assertSame('Accepted', Delivery::find('ffffffff-ffff-4fff-8fff-ffffffffffff')->state);
    }
}
