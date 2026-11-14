<?php
namespace Tests\Feature;

use App\Models\ProgressReviewDraft;
use App\Models\ReviewReminder;
use App\Models\SignedReviewRecord;
use App\Support\ReviewReminders;
use App\Support\SignedReviews;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesOperationalPlan;
use Tests\TestCase;

class ReviewRemindersTest extends TestCase
{
    use LazilyRefreshDatabase,CreatesOperationalPlan;
    private function fixture(int $days=80,bool $confirm=true): array
    {
        Storage::fake('local');$f=$this->approvedFixture();[$a,$admin,$ceo,$tutor]=$f;
        $this->actingAs($admin)->post('/apprentices/'.$a->id.'/outcome',['version'=>0,'context'=>['status'=>'Active','sourceRef'=>'Fictional active enrolment source']])->assertSessionHasNoErrors();
        $mentor=$this->newActor('mentor');$a->mentor_id=$mentor->id;$a->save();
        [$review,$record]=$this->meeting($a,$tutor,$mentor,$admin,$days,$confirm);return [...$f,$mentor,$review,$record];
    }
    private function meeting($a,$tutor,$mentor,$admin,int $days,bool $confirm=true): array
    {
        $fields=['reviewDate'=>today()->subDays($days)->toDateString(),'trainingFrom'=>today()->subDays($days+30)->toDateString(),'mentor'=>$mentor->name,'tutor'=>$tutor->name,'employerRep'=>'Fictional Employer Representative','method'=>'Face to face','attendance'=>'Present','judgement'=>'Behind plan','previousCheck'=>'Existing action plan checked','planChange'=>'No','summary'=>'Fictional review','learning'=>'Actual learning checked','progressDiscussion'=>'Evidence discussed','previousNotes'=>'N/A','planChangeNotes'=>'N/A','sharedSupport'=>'N/A','absence'=>'N/A','newNeeds'=>'No','trainingChecked'=>1,'targetsAgreed'=>1,'providerPrepared'=>1];
        $this->actingAs($tutor)->post('/apprentices/'.$a->id.'/reviews',['fields'=>$fields])->assertSessionHasNoErrors();$r=ProgressReviewDraft::where('apprentice_id',$a->id)->latest('id')->firstOrFail();
        $this->post('/apprentices/'.$a->id.'/reviews/'.$r->id.'/ready',['version'=>1,'confirmed'=>1])->assertSessionHasNoErrors();$r->refresh();
        $pdf=$this->get('/apprentices/'.$a->id.'/reviews/'.$r->id.'/draft.pdf')->assertOk()->getContent();
        // Fictional unsigned fixture tests attestation flow, not authenticity of signatures.
        $data=['version'=>$r->version,'source_reference'=>'Fictional external source','identity_reference'=>'Fictional participant checks','apprentice_name'=>$a->first_name.' '.$a->last_name,'apprentice_date'=>today()->toDateString(),'employer_name'=>$fields['employerRep'],'employer_date'=>today()->toDateString(),'mentor_name'=>$mentor->name,'mentor_date'=>today()->toDateString()];
        $record=SignedReviews::prepare($r,$tutor,UploadedFile::fake()->createWithContent('fictional.pdf',$pdf),$data);
        if($confirm){SignedReviews::confirm($record,$admin,1);}return [$r,$record->fresh()];
    }
    public function test_pending_copy_and_provisional_first_reference_do_not_create_reminders(): void
    {
        [$a,$admin,$ceo,$tutor,$learner,$adviser,$plan,$mentor,$review]=$this->fixture(80,false);
        $this->assertTrue($a->start_date->copy()->addDays(70)->lt(today()));$this->assertSame('Ready for signatures',$review->state);
        $p=ReviewReminders::generate(true,$admin);$this->assertSame(1,$p['history_checks']);$this->assertSame(0,$p['due']);$this->assertDatabaseCount('review_reminders',0);
        $this->actingAs($tutor)->get('/reviews')->assertOk()->assertSee('no automatic reminder is generated')->assertDontSee('Mark review reminder seen');
    }
    public function test_due_prompts_preview_record_deduplicate_and_acknowledge_without_completing_review(): void
    {
        [$a,$admin,$ceo,$tutor,$learner,$adviser,$plan,$mentor,$review]=$this->fixture();$before=$review->getAttributes();$due=SignedReviews::cycle($a)['next_due'];
        $p=ReviewReminders::generate();$this->assertSame(1,$p['due']);$this->assertSame(2,$p['eligible']);$this->assertSame(0,$p['created']);$this->assertDatabaseCount('review_reminders',0);
        $this->actingAs($admin)->get('/reviews')->assertOk()->assertSee('In-portal review reminders')->assertSee('Record current due reminders');
        $this->post('/reviews/reminders')->assertSessionHasNoErrors();$this->post('/reviews/reminders')->assertSessionHasNoErrors();$this->assertDatabaseCount('review_reminders',2);$this->assertDatabaseCount('review_reminder_events',2);
        foreach([$tutor,$mentor] as $owner){$r=ReviewReminder::where('recipient_id',$owner->id)->firstOrFail();$this->actingAs($owner)->get('/reviews')->assertSee('Mark review reminder seen')->assertDontSee('Record current due reminders');$this->post('/reviews/reminders/'.$r->id.'/seen')->assertSessionHasNoErrors();$this->post('/reviews/reminders/'.$r->id.'/seen')->assertSessionHasNoErrors();$this->assertNotNull($r->fresh()->seen_at);}
        $this->assertDatabaseCount('review_reminder_events',4);$this->assertSame(0,ReviewReminders::generate(true)['created']);$this->assertSame(0,ReviewReminders::generate()['awaiting_seen']);$this->assertSame($before,$review->fresh()->getAttributes());$this->assertSame($due,SignedReviews::cycle($a)['next_due']);$this->assertDatabaseCount('signed_review_events',2);
    }
    public function test_actual_meeting_plus_seventy_boundary_and_no_daily_repeat(): void
    {
        [$a]=$this->fixture(69);$this->assertSame(0,ReviewReminders::generate(true)['due']);$this->travel(1)->days();$this->assertSame(today()->toDateString(),SignedReviews::cycle($a)['next_due']);$this->assertSame(2,ReviewReminders::generate(true)['created']);
        $this->travel(1)->days();$p=ReviewReminders::generate(true);$this->assertSame(1,$p['due']);$this->assertSame(2,$p['already_recorded']);$this->assertSame(0,$p['created']);$this->assertDatabaseCount('review_reminders',2);
    }
    public function test_wrong_role_inactive_and_missing_assignments_are_checks(): void
    {
        [$a,$admin,$ceo,$tutor,$learner,$adviser,$plan,$mentor]=$this->fixture();$a->tutor_id=$adviser->id;$a->mentor_id=null;$a->save();$p=ReviewReminders::generate(true);$this->assertSame(2,$p['owner_checks']);$this->assertSame(0,$p['created']);
        $a->tutor_id=$tutor->id;$a->mentor_id=$mentor->id;$a->save();$mentor->active=false;$mentor->save();$p=ReviewReminders::generate(true);$this->assertSame(1,$p['owner_checks']);$this->assertSame(1,$p['created']);$tutor->active=false;$tutor->save();$this->assertSame(0,ReviewReminders::generate(true)['eligible']);
        $admin->active=false;$admin->save();try{ReviewReminders::generate(true,$admin);$this->fail('Inactive actor rejected');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(403,$e->getStatusCode());}
    }
    public function test_cross_recipient_and_unrelated_roles_cannot_acknowledge_or_record(): void
    {
        [$a,$admin,$ceo,$tutor,$learner,$adviser,$plan,$mentor]=$this->fixture();ReviewReminders::generate(true);$r=ReviewReminder::where('recipient_id',$tutor->id)->firstOrFail();
        foreach([$admin,$ceo,$mentor,$learner,$adviser,$this->newActor('tutor'),$this->newActor('mentor')] as $u){$this->actingAs($u)->post('/reviews/reminders/'.$r->id.'/seen')->assertForbidden();}
        foreach([$tutor,$mentor,$learner,$adviser] as $u){$this->actingAs($u)->post('/reviews/reminders')->assertForbidden();}
        foreach([$learner,$adviser,$this->newActor('functional_skills_tutor')] as $u){$this->actingAs($u)->get('/reviews')->assertForbidden();}
        $this->actingAs($this->newActor('tutor'))->get('/reviews')->assertOk()->assertDontSee($a->reference);$this->assertNull($r->fresh()->seen_at);$this->assertDatabaseCount('review_reminder_events',2);
    }
    public function test_reassignment_retains_old_source_and_only_current_staff_receive_prompts(): void
    {
        [$a,$admin,$ceo,$tutor]=$this->fixture();ReviewReminders::generate(true);$old=ReviewReminder::where('recipient_id',$tutor->id)->firstOrFail();$replacement=$this->newActor('tutor');$a->tutor_id=$replacement->id;$a->save();
        $this->actingAs($tutor)->get('/reviews')->assertDontSee($a->reference);$this->post('/reviews/reminders/'.$old->id.'/seen')->assertForbidden();$this->assertSame(1,ReviewReminders::generate(true)['created']);$this->assertDatabaseCount('review_reminders',3);
        $new=ReviewReminder::where('recipient_id',$replacement->id)->firstOrFail();$this->actingAs($replacement)->post('/reviews/reminders/'.$new->id.'/seen')->assertSessionHasNoErrors();$this->assertNull($old->fresh()->seen_at);
    }
    public function test_new_checked_meeting_supersedes_pending_does_not_and_withdrawal_reuses_prior_prompt(): void
    {
        [$a,$admin,$ceo,$tutor,$learner,$adviser,$plan,$mentor,$review,$record]=$this->fixture();ReviewReminders::generate(true);$old=ReviewReminder::where('recipient_id',$tutor->id)->firstOrFail();
        [$nr,$new]=$this->meeting($a,$tutor,$mentor,$admin,0,false);$this->assertSame(2,ReviewReminders::generate()['already_recorded']);SignedReviews::confirm($new,$admin,1);$this->assertSame(0,ReviewReminders::generate(true)['due']);
        $this->actingAs($tutor)->get('/reviews')->assertDontSee('Mark review reminder seen');$this->post('/reviews/reminders/'.$old->id.'/seen')->assertSessionHasErrors('reminder');SignedReviews::withdraw($new->fresh(),$admin,2,'Fictional correction');$p=ReviewReminders::generate(true);$this->assertSame(2,$p['already_recorded']);$this->assertSame(0,$p['created']);$this->post('/reviews/reminders/'.$old->id.'/seen')->assertSessionHasNoErrors();
        SignedReviews::withdraw($record,$admin,2,'Reconcile remaining source');$this->assertSame(1,ReviewReminders::generate()['history_checks']);$this->assertDatabaseCount('review_reminders',2);$r=ReviewReminder::where('recipient_id',$mentor->id)->firstOrFail();$this->actingAs($mentor)->post('/reviews/reminders/'.$r->id.'/seen')->assertSessionHasErrors('reminder');
    }
    public function test_corrupt_checked_source_or_reminder_is_flagged_without_acknowledgement_or_new_copy(): void
    {
        [$a,$admin,$ceo,$tutor,$learner,$adviser,$plan,$mentor,$review,$record]=$this->fixture();ReviewReminders::generate(true);$r=ReviewReminder::where('recipient_id',$tutor->id)->firstOrFail();$source=$r->source;$source['due_date']=today()->toDateString();$r->source=$source;$r->save();
        $p=ReviewReminders::generate(true);$this->assertSame(1,$p['source_checks']);$this->assertSame(0,$p['created']);$this->actingAs($tutor)->get('/reviews')->assertSee('Source needs checking')->assertDontSee('Mark review reminder seen');$this->post('/reviews/reminders/'.$r->id.'/seen')->assertSessionHasErrors('reminder');
        $record->snapshot_hash=str_repeat('0',64);$record->save();$p=ReviewReminders::generate(true);$this->assertSame(1,$p['history_checks']);$this->assertSame(0,$p['due']);$this->assertNull($r->fresh()->seen_at);
    }
    public function test_failed_audits_roll_back_generation_seen_and_whole_combined_run(): void
    {
        [$a,$admin,$ceo,$tutor]=$this->fixture();\Tests\Support\AuditFault::install('reject_review_event','review_reminder_events','Fictional review audit failure');$this->actingAs($admin)->post('/reviews/reminders')->assertStatus(500);$this->assertDatabaseCount('review_reminders',0);DB::statement('DROP TRIGGER reject_review_event');ReviewReminders::generate(true);$r=ReviewReminder::where('recipient_id',$tutor->id)->firstOrFail();
        \Tests\Support\AuditFault::install('reject_review_event','review_reminder_events','Fictional review audit failure');$this->actingAs($tutor)->post('/reviews/reminders/'.$r->id.'/seen')->assertStatus(500);$this->assertNull($r->fresh()->seen_at);DB::statement('DROP TRIGGER reject_review_event');
        $a->tutor_id=$this->newActor('tutor')->id;$a->save();\Tests\Support\AuditFault::install('reject_review_run','portal_reminder_runs','Fictional run audit failure');
        try{$this->artisan('gta:portal-reminders',['--record'=>true])->run();$this->fail('Expected failed run audit');}catch(\Illuminate\Database\QueryException $e){$this->assertStringContainsString('Fictional run audit failure',$e->getMessage());}
        $this->assertDatabaseCount('review_reminders',2);$this->assertDatabaseCount('review_reminder_events',2);$this->assertDatabaseCount('careers_prompts',0);$this->assertDatabaseCount('portal_reminder_runs',0);
    }
    public function test_only_sourced_active_programmes_get_reminders_and_pause_rejects_old_acknowledgement(): void
    {
        [$a,$admin,$ceo,$tutor]=$this->fixture();ReviewReminders::generate(true);$r=ReviewReminder::where('recipient_id',$tutor->id)->firstOrFail();
        $outcome=\App\Models\ProgrammeOutcome::where('apprentice_id',$a->id)->firstOrFail();$original=$outcome->context;
        foreach(['Break in learning','Completed','Withdrawn','Transferred'] as $status){$outcome->context=[...$original,'status'=>$status];$outcome->save();$p=ReviewReminders::generate(true);$this->assertSame(1,$p['paused']);$this->assertSame(0,$p['due']);$this->actingAs($tutor)->post('/reviews/reminders/'.$r->id.'/seen')->assertSessionHasErrors('reminder');}
        foreach([[],['status'=>'Unknown'],['status'=>'Active','sourceRef'=>'']] as $context){$outcome->context=$context;$outcome->save();$p=ReviewReminders::generate(true);$this->assertSame(1,$p['programme_checks']);$this->assertSame(0,$p['created']);}
        $outcome->context=$original;$outcome->save();$this->assertSame(2,ReviewReminders::generate(true)['already_recorded']);$this->actingAs($tutor)->post('/reviews/reminders/'.$r->id.'/seen')->assertSessionHasNoErrors();$this->assertDatabaseCount('review_reminders',2);
    }
    public function test_combined_command_preview_counts_and_opt_in_schedule_without_mail(): void
    {
        $this->fixture();Mail::fake();$this->artisan('gta:portal-reminders')->assertSuccessful();$this->assertDatabaseCount('review_reminders',0);$this->assertDatabaseCount('portal_reminder_runs',0);$event=collect(Schedule::events())->first(fn($e)=>str_contains($e->command??'','gta:portal-reminders'));$this->assertNotNull($event);$this->assertFalse($event->filtersPass($this->app));config(['portal_reminders.scheduled'=>true]);$this->assertTrue($event->filtersPass($this->app));$this->assertTrue($event->withoutOverlapping);
        $this->artisan('gta:portal-reminders',['--record'=>true])->assertSuccessful();$this->assertDatabaseCount('review_reminders',2);$run=DB::table('portal_reminder_runs')->first();$counts=json_decode($run->counts,true,512,JSON_THROW_ON_ERROR);$this->assertSame(2,$counts['reviews']['created']);$this->assertArrayHasKey('marking',$counts);$this->assertArrayHasKey('careers',$counts);$this->artisan('gta:portal-reminders',['--record'=>true])->assertSuccessful();$this->assertDatabaseCount('review_reminders',2);$this->assertDatabaseCount('portal_reminder_runs',2);Mail::assertNothingSent();Mail::assertNothingQueued();
    }
}
