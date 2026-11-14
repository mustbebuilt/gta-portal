<?php
namespace Tests\Feature;
use App\Models\{Apprentice,LearningTask,MarkingReminder,ReminderMail,User};
use App\Notifications\PortalWorkPrompt;
use App\Support\{MarkingReminders,ReminderDelivery};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB,Notification};
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Concerns\ConfiguresStaging;
use Tests\TestCase;
class ReminderDeliveryTest extends TestCase
{
    use DatabaseMigrations,ConfiguresStaging;
    private function fixture(): array
    {
        $admin=User::factory()->create(['role'=>'admin']);$tutor=User::factory()->create(['email'=>'tutor@example.test','role'=>'tutor']);
        $a=Apprentice::factory()->create(['tutor_id'=>$tutor->id]);$task=LearningTask::factory()->create(['apprentice_id'=>$a->id]);$at=now()->subDays(8);
        $task->status='Awaiting marking';$task->submitted_at=$at;$task->submissions=[['version'=>1,'submitted_at'=>$at->toIso8601String(),'template_hash'=>$task->template['sha256'],'answers'=>['private'=>'PRIVATE-LEARNER-ANSWER'],'decision'=>null]];$task->save();MarkingReminders::generate(true);
        $this->configureStaging();config(['reminder_delivery.enabled'=>true,'reminder_delivery.recipients'=>[$tutor->email],'mail.default'=>'smtp','mail.mailers.smtp'=>['transport'=>'smtp','scheme'=>'smtps','url'=>null,'host'=>'smtp.example.test','port'=>465,'timeout'=>10],'mail.from.address'=>'portal@example.test']);return [$admin,$tutor,$a,$task];
    }
    private function prepare($admin,$tutor): ReminderMail
    {
        $snapshot=ReminderDelivery::candidate($tutor)['snapshot'];return ReminderDelivery::prepare($admin,$admin->auth_version,$tutor->id,ReminderDelivery::hash($snapshot));
    }
    private function reviewed($admin,$tutor): ReminderMail
    {
        $row=$this->prepare($admin,$tutor);ReminderDelivery::transition($row,$admin,$admin->auth_version,1,'review');return $row->fresh();
    }
    private function postPilot(string $p,array $data=[]){return $this->withSession(['_token'=>'fixture-csrf'])->post('https://staging.example.test'.$p,[...$data,'_token'=>'fixture-csrf']);}
    public function test_default_disabled_and_independent_staging_flag_and_transport_checks(): void
    {
        $this->assertFalse(ReminderDelivery::available());$this->fixture();$this->assertTrue(ReminderDelivery::available());config(['reminder_delivery.enabled'=>false,'onboarding.enabled'=>true,'account_access.staging_recovery_enabled'=>true]);$this->assertFalse(ReminderDelivery::available());config(['reminder_delivery.enabled'=>true,'mail.mailers.smtp.scheme'=>'smtp']);$this->assertContains('remote_smtp_requires_smtps',ReminderDelivery::errors());$this->app['env']='production';$this->assertFalse(ReminderDelivery::available());$this->app['env']='staging';
    }
    public function test_candidate_and_default_console_are_read_only_with_no_private_payload(): void
    {
        [$admin,$tutor]=$this->fixture();Notification::fake();$items=ReminderDelivery::preview();$this->assertCount(1,$items);$this->assertCount(1,$items[0]['snapshot']['items']);$this->assertStringNotContainsString('PRIVATE-LEARNER-ANSWER',json_encode($items[0]['snapshot']));$this->assertStringNotContainsString($tutor->email,json_encode($items[0]['snapshot']));$this->artisan('gta:reminder-mail')->assertExitCode(0);$this->artisan('gta:reminder-mail-check')->assertExitCode(0);$this->assertDatabaseCount('reminder_mail_outbox',0);Notification::assertNothingSent();$this->assertNull(MarkingReminder::first()->seen_at);
    }
    public function test_snapshot_preparation_deduplicates_and_does_not_send_or_change_source(): void
    {
        [$admin,$tutor,$a,$task]=$this->fixture();$before=$task->fresh()->getAttributes();Notification::fake();$row=$this->prepare($admin,$tutor);$second=$this->prepare($admin,$tutor);$this->assertSame($row->id,$second->id);$this->assertDatabaseCount('reminder_mail_outbox',1);$this->assertDatabaseCount('reminder_mail_events',1);$this->assertSame($before,$task->fresh()->getAttributes());Notification::assertNothingSent();
    }
    public function test_stale_preview_or_unverified_inactive_or_ambiguous_account_cannot_prepare(): void
    {
        [$admin,$tutor]=$this->fixture();$hash=ReminderDelivery::hash(ReminderDelivery::candidate($tutor)['snapshot']);$tutor->email_verified_at=null;$tutor->save();$this->assertNull(ReminderDelivery::candidate($tutor)['snapshot']);$this->actingAs($admin)->postPilot('/reminder-mail',['recipient_id'=>$tutor->id,'fingerprint'=>$hash])->assertSessionHasErrors('delivery');$tutor->email_verified_at=now();$tutor->active=false;$tutor->save();$this->assertNull(ReminderDelivery::candidate($tutor)['snapshot']);$tutor->active=true;$tutor->save();User::factory()->create(['email'=>strtoupper($tutor->email),'role'=>'tutor']);$this->assertNull(ReminderDelivery::candidate($tutor)['snapshot']);$this->assertDatabaseCount('reminder_mail_outbox',0);
    }
    public function test_unseen_source_reconciliation_excludes_seen_reassigned_and_corrupt_rows(): void
    {
        [$admin,$tutor,$a,$task]=$this->fixture();$r=MarkingReminder::first();$r->seen_at=now();$r->save();$this->assertNull(ReminderDelivery::candidate($tutor)['snapshot']);$r->seen_at=null;$r->save();$a->tutor_id=$admin->id;$a->save();$this->assertNull(ReminderDelivery::candidate($tutor)['snapshot']);$a->tutor_id=$tutor->id;$a->save();$r->source_key=str_repeat('f',64);$r->save();$candidate=ReminderDelivery::candidate($tutor);$this->assertNull($candidate['snapshot']);$this->assertSame(1,$candidate['checks']);
    }
    public function test_explicit_review_and_dispatch_accepts_once_without_marking_work_complete(): void
    {
        [$admin,$tutor,$a,$task]=$this->fixture();Notification::fake();$row=$this->reviewed($admin,$tutor);Notification::assertNothingSent();$result=ReminderDelivery::dispatch($row->id);$this->assertSame(['result'=>'accepted','mail_attempted'=>true],$result);Notification::assertSentToTimes($tutor,PortalWorkPrompt::class,1);$this->assertSame('accepted',$row->fresh()->state);$this->assertSame('Awaiting marking',$task->fresh()->status);$this->assertNull(MarkingReminder::first()->seen_at);$this->assertSame('not_dispatchable',ReminderDelivery::dispatch($row->id)['result']);Notification::assertSentToTimes($tutor,PortalWorkPrompt::class,1);$this->assertDatabaseCount('reminder_mail_events',4);
    }
    public function test_dispatch_refuses_unreviewed_cancelled_reserved_and_missing_records(): void
    {
        [$admin,$tutor]=$this->fixture();$row=$this->prepare($admin,$tutor);Notification::fake();$this->assertSame('not_dispatchable',ReminderDelivery::dispatch($row->id)['result']);ReminderDelivery::transition($row,$admin,1,1,'cancel');$this->assertSame('not_dispatchable',ReminderDelivery::dispatch($row->id)['result']);$row->state='dispatching';$row->save();$this->assertSame('not_dispatchable',ReminderDelivery::dispatch($row->id)['result']);$this->assertSame('not_dispatchable',ReminderDelivery::dispatch(999999)['result']);Notification::assertNothingSent();
    }
    public function test_outer_transaction_refused_to_ensure_reservation_commit_before_smtp(): void
    {
        [$admin,$tutor]=$this->fixture();$row=$this->reviewed($admin,$tutor);Notification::fake();DB::transaction(function()use($row){$this->assertSame('outer_transaction_refused',ReminderDelivery::dispatch($row->id)['result']);});$this->assertSame('reviewed',$row->fresh()->state);Notification::assertNothingSent();
    }
    public function test_changed_source_or_expired_review_marks_record_stale_without_sending(): void
    {
        [$admin,$tutor]=$this->fixture();$row=$this->reviewed($admin,$tutor);MarkingReminder::query()->update(['seen_at'=>now()]);Notification::fake();$this->assertSame('not_dispatchable',ReminderDelivery::dispatch($row->id)['result']);$this->assertSame('stale',$row->fresh()->state);Notification::assertNothingSent();
    }
    public function test_review_expires_at_fifteen_minute_boundary(): void
    {
        [$admin,$tutor]=$this->fixture();$row=$this->reviewed($admin,$tutor);$this->travel(15)->minutes();Notification::fake();$this->assertSame('not_dispatchable',ReminderDelivery::dispatch($row->id)['result']);$this->assertSame('stale',$row->fresh()->state);Notification::assertNothingSent();
    }
    public function test_approver_authority_version_and_recipient_access_are_rechecked(): void
    {
        [$admin,$tutor]=$this->fixture();$row=$this->reviewed($admin,$tutor);$admin->auth_version++;$admin->save();Notification::fake();ReminderDelivery::dispatch($row->id);$this->assertSame('stale',$row->fresh()->state);Notification::assertNothingSent();
    }
    public function test_removal_from_pilot_and_disabled_flag_block_attempt_without_consuming_review(): void
    {
        [$admin,$tutor]=$this->fixture();$row=$this->reviewed($admin,$tutor);config(['reminder_delivery.enabled'=>false]);Notification::fake();$this->assertSame('blocked',ReminderDelivery::dispatch($row->id)['result']);$this->assertSame('reviewed',$row->fresh()->state);config(['reminder_delivery.enabled'=>true,'reminder_delivery.recipients'=>['other@example.test']]);ReminderDelivery::dispatch($row->id);$this->assertSame('stale',$row->fresh()->state);Notification::assertNothingSent();
    }
    public function test_transport_failure_is_terminal_needs_check_and_never_exposes_or_retries_raw_error(): void
    {
        [$admin,$tutor]=$this->fixture();$row=$this->reviewed($admin,$tutor);Notification::shouldReceive('send')->once()->andThrow(new TransportException('PRIVATE SMTP BODY AND CREDENTIAL'));$result=ReminderDelivery::dispatch($row->id);$this->assertSame(['result'=>'needs_check','mail_attempted'=>true],$result);$this->assertSame('needs_check',$row->fresh()->state);$this->assertSame('not_dispatchable',ReminderDelivery::dispatch($row->id)['result']);$this->assertStringNotContainsString('PRIVATE SMTP',json_encode(DB::table('reminder_mail_events')->get()));
    }
    public function test_email_has_only_generic_text_and_fixed_origin_login_link(): void
    {
        [$admin,$tutor,$a,$task]=$this->fixture();$mail=(new PortalWorkPrompt('https://staging.example.test'))->toMail($tutor);$this->assertSame('https://staging.example.test/login',$mail->actionUrl);$render=$mail->render();foreach([$a->reference,$a->last_name,$tutor->email,'PRIVATE-LEARNER-ANSWER',$task->title]as$value){$this->assertStringNotContainsString($value,(string)$render);}$this->assertStringContainsString('check your current worklists',(string)$render);
    }
    public function test_queue_role_scope_csrf_and_exact_version_transition_guard(): void
    {
        [$admin,$tutor]=$this->fixture();$row=$this->prepare($admin,$tutor);$this->actingAs($tutor)->get('https://staging.example.test/reminder-mail')->assertForbidden();$this->get('https://staging.example.test/reminder-mail/'.$row->id)->assertForbidden();$this->postPilot('/reminder-mail/'.$row->id,['action'=>'cancel','version'=>1,'confirmed'=>1])->assertForbidden();$this->actingAs($admin);$this->post('https://staging.example.test/reminder-mail/'.$row->id,['action'=>'cancel','version'=>1,'confirmed'=>1])->assertStatus(419);$this->postPilot('/reminder-mail/'.$row->id,['action'=>'review','version'=>0,'confirmed'=>1])->assertSessionHasErrors('delivery');$this->postPilot('/reminder-mail/'.$row->id,['action'=>'review','version'=>1])->assertSessionHasErrors('confirmed');$this->postPilot('/reminder-mail/'.$row->id,['action'=>'review','version'=>1,'confirmed'=>1])->assertSessionHasNoErrors();$this->assertSame('reviewed',$row->fresh()->state);
    }
    public function test_disabled_pilot_allows_cancellation_and_never_deletes_audit_or_sources(): void
    {
        [$admin,$tutor]=$this->fixture();$row=$this->reviewed($admin,$tutor);config(['reminder_delivery.enabled'=>false]);$this->actingAs($admin)->postPilot('/reminder-mail/'.$row->id,['action'=>'cancel','version'=>2,'confirmed'=>1])->assertSessionHasNoErrors();$this->assertSame('cancelled',$row->fresh()->state);$this->assertDatabaseCount('reminder_mail_events',3);$this->assertDatabaseCount('marking_reminders',1);$this->assertNull(MarkingReminder::first()->seen_at);
    }
    public function test_database_json_key_reordering_preserves_binding_and_tampering_is_rejected(): void
    {
        [$admin,$tutor]=$this->fixture();$row=$this->prepare($admin,$tutor);$snapshot=$row->snapshot;ksort($snapshot);$row->snapshot=$snapshot;$row->save();ReminderDelivery::transition($row,$admin,1,1,'review');$row->refresh();$snapshot=$row->snapshot;$snapshot['origin']='https://wrong.example.test';$row->snapshot=$snapshot;$row->save();Notification::fake();ReminderDelivery::dispatch($row->id);$this->assertSame('stale',$row->fresh()->state);Notification::assertNothingSent();
    }
    public function test_admin_queue_filters_and_paginates_without_source_answers_or_hashes(): void
    {
        [$admin,$tutor]=$this->fixture();$row=$this->prepare($admin,$tutor);for($i=0;$i<21;$i++){ReminderMail::create(['recipient_id'=>$tutor->id,'prepared_by'=>$admin->id,'fingerprint'=>hash('sha256','fictional'.$i),'snapshot'=>[],'state'=>'cancelled']);}$this->actingAs($admin)->get('https://staging.example.test/reminder-mail?state=prepared')->assertOk()->assertSee('Delivery #'.$row->id)->assertDontSee('PRIVATE-LEARNER-ANSWER');$this->get('https://staging.example.test/reminder-mail?state=cancelled&page=2')->assertOk()->assertSee('state=cancelled')->assertSee('page=1');$this->get('https://staging.example.test/reminder-mail?state=invalid')->assertSessionHasErrors('state');$this->get('https://staging.example.test/reminder-mail/'.$row->id)->assertOk()->assertDontSee($row->fingerprint)->assertDontSee('PRIVATE-LEARNER-ANSWER');
    }
    public function test_console_rejects_invalid_id_and_inspector_excludes_mail_credentials(): void
    {
        $this->fixture();Notification::fake();$this->artisan('gta:reminder-mail',['--send'=>'../bad'])->assertExitCode(1);$json=json_encode(ReminderDelivery::inspect());foreach(['tutor@example.test','smtp.example.test','https://staging.example.test']as$v){$this->assertStringNotContainsString($v,$json);}Notification::assertNothingSent();
    }
    public function test_oversized_unseen_source_set_blocks_recipient_instead_of_sending_partial_digest(): void
    {
        [$admin,$tutor]=$this->fixture();$first=MarkingReminder::first();for($i=0;$i<100;$i++){$clone=$first->replicate();$clone->source_key=hash('sha256','fictional-overlimit-'.$i);$clone->save();}$candidate=ReminderDelivery::candidate($tutor);$this->assertNull($candidate['snapshot']);$this->assertSame(1,$candidate['checks']);$this->assertDatabaseCount('reminder_mail_outbox',0);
    }
    #[\PHPUnit\Framework\Attributes\TestWith(['active',false])]
    #[\PHPUnit\Framework\Attributes\TestWith(['role','mentor'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['email','changed@example.test'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['auth_version',2])]
    public function test_recipient_access_changes_stale_the_reviewed_delivery(string $field,mixed $value): void
    {
        [$admin,$tutor]=$this->fixture();$row=$this->reviewed($admin,$tutor);$tutor->$field=$value;$tutor->save();Notification::fake();ReminderDelivery::dispatch($row->id);$this->assertSame('stale',$row->fresh()->state);Notification::assertNothingSent();
    }
    public function test_reservation_is_committed_before_notification_and_reentrant_attempt_cannot_send_twice(): void
    {
        [$admin,$tutor]=$this->fixture();$row=$this->reviewed($admin,$tutor);Notification::shouldReceive('send')->once()->andReturnUsing(function()use($row){$this->assertSame(0,DB::transactionLevel());$this->assertSame('dispatching',$row->fresh()->state);$this->assertSame('not_dispatchable',ReminderDelivery::dispatch($row->id)['result']);});$this->assertSame('accepted',ReminderDelivery::dispatch($row->id)['result']);
    }
    public function test_database_finish_failure_after_mail_acceptance_leaves_non_retryable_unknown_reservation(): void
    {
        if(DB::getDriverName()!=='sqlite'){$this->markTestSkipped('SQLite-only disposable fault-injection trigger; native acceptance remains separate.');}
        [$admin,$tutor]=$this->fixture();$row=$this->reviewed($admin,$tutor);Notification::fake();DB::statement("CREATE TRIGGER fictional_finish_failure BEFORE UPDATE OF state ON reminder_mail_outbox WHEN NEW.state = 'accepted' BEGIN SELECT RAISE(ABORT, 'Fictional finish failure'); END");
        $this->assertSame(['result'=>'outcome_unknown','mail_attempted'=>true],ReminderDelivery::dispatch($row->id));$this->assertSame('dispatching',$row->fresh()->state);$this->assertSame('not_dispatchable',ReminderDelivery::dispatch($row->id)['result']);Notification::assertSentToTimes($tutor,PortalWorkPrompt::class,1);DB::statement('DROP TRIGGER fictional_finish_failure');
    }
}
