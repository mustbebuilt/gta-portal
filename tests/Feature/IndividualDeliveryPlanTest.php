<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\IndividualDeliveryPlan as Plan;
use App\Models\LearningTask;
use App\Models\ProgressReviewDraft;
use App\Models\Programme;
use App\Models\User;
use App\Support\DeliveryPlan;
use App\Support\TaskCatalogue;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class IndividualDeliveryPlanTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role): User
    {
        $u = User::factory()->create(); $u->role = $role; $u->save(); return $u;
    }

    private function fixture(string $key = 'autocare'): array
    {
        $p = TaskCatalogue::PROGRAMMES[$key];
        $programme = Programme::factory()->create(['standard_code' => $p['code'], 'version' => $p['version']]);
        $admin = $this->actor('admin'); $reviewer = $this->actor('ceo'); $tutor = $this->actor('tutor'); $learner = $this->actor('apprentice');
        $a = Apprentice::factory()->create(['programme_id' => $programme->id, 'start_date' => today()->subDays(90)->toDateString(), 'employer' => 'Fictional delivery employer', 'tutor_id' => $tutor->id]);
        $a->user_id = $learner->id; $a->save();
        return [$a, $admin, $reviewer, $tutor, $learner];
    }

    private function calendar(Apprentice $a): array
    {
        return collect(range(0,11))->map(fn ($n) => ['start' => $a->start_date->copy()->addDays($n*20)->toDateString(), 'end' => $a->start_date->copy()->addDays($n*20+19)->toDateString(), 'hours' => $n === 11 ? 0 : 10])->all();
    }

    private function details(int $version = 1): array
    {
        return ['version' => $version, 'source_ref' => 'Fictional controlled plan v1', 'ksb_ref' => 'Fictional reviewed primary KSB mapping v1', 'qualifications_ref' => 'Fictional separate Maths/English and mandatory qualification record', 'release_ref' => 'Fictional employer release evidence', 'target_hours' => 110, 'basis' => 'Full training retained'];
    }

    private function checks(int $version): array
    {
        return ['version' => $version, 'source_checked' => 1, 'hours_checked' => 1, 'curriculum_checked' => 1, 'release_checked' => 1];
    }

    private function approvePlan(array $f): Plan
    {
        [$a,$admin,$reviewer] = $f;
        $this->actingAs($admin)->post('/apprentices/'.$a->id.'/delivery-plan/calendar', ['version'=>0,'blocks'=>$this->calendar($a)])->assertSessionHasNoErrors();
        $this->post('/apprentices/'.$a->id.'/delivery-plan/details',$this->details())->assertSessionHasNoErrors();
        $this->actingAs($reviewer)->post('/apprentices/'.$a->id.'/delivery-plan/approve',$this->checks(2))->assertSessionHasNoErrors();
        return Plan::where('apprentice_id',$a->id)->firstOrFail();
    }

    #[TestWith(['autocare'])] #[TestWith(['heavy-vehicle'])] #[TestWith(['paint'])] #[TestWith(['panel'])]
    public function test_calendar_and_independent_approval_work_for_each_route_without_changing_profile_or_hours(string $key): void
    {
        $f = $this->fixture($key); [$a,$admin,$reviewer,$tutor,$learner] = $f;
        $profile = $a->fresh()->getAttributes(); $row = $this->approvePlan($f);
        $this->assertSame(3,$row->version); $this->assertTrue($row->active);
        $this->assertSame($profile,$a->fresh()->getAttributes());
        $this->assertSame(110.0,(float)array_sum(array_column($row->approved['blocks'],'hours')));
        $this->assertDatabaseCount('learning_tasks',0);
        $this->assertDatabaseCount('attendance_marks',0);
        $this->actingAs($learner)->get('/apprentices/'.$a->id.'/delivery-plan')->assertOk()->assertSee('Provider approved')->assertDontSee('Save draft calendar');
        $this->get('/apprentices/'.$a->id.'/delivery-plan/approved.pdf')->assertOk()->assertDownload();
        $this->actingAs($tutor)->get('/apprentices/'.$a->id.'/delivery-plan')->assertOk();
        $this->actingAs($reviewer)->post('/apprentices/'.$a->id.'/delivery-plan/approve',$this->checks(3))->assertSessionHasErrors('version');
        $this->assertSame(3,$row->fresh()->version);
    }

    #[TestWith(['overlap'])] #[TestWith(['reverse'])] #[TestWith(['before_learning'])] #[TestWith(['missing_end'])] #[TestWith(['epa_hours'])] #[TestWith(['negative'])]
    public function test_invalid_dates_and_hours_never_create_a_plan(string $case): void
    {
        [$a,$admin] = $this->fixture(); $blocks = $this->calendar($a);
        if ($case === 'overlap') { $blocks[1]['start'] = $blocks[0]['end']; }
        if ($case === 'reverse') { $blocks[0]['end'] = $a->start_date->copy()->subDay()->toDateString(); }
        if ($case === 'before_learning') { $blocks[0]['start'] = $a->start_date->copy()->subDay()->toDateString(); }
        if ($case === 'missing_end') { $blocks[0]['end'] = null; }
        if ($case === 'epa_hours') { $blocks[11]['hours'] = 1; }
        if ($case === 'negative') { $blocks[1]['hours'] = -1; }
        $this->actingAs($admin)->post('/apprentices/'.$a->id.'/delivery-plan/calendar',['version'=>0,'blocks'=>$blocks])->assertSessionHasErrors();
        $this->assertDatabaseCount('individual_delivery_plans',0);
    }

    public function test_partial_calendar_remains_draft_and_stale_self_and_missing_checks_cannot_approve(): void
    {
        [$a,$admin,$reviewer] = $this->fixture(); $url='/apprentices/'.$a->id.'/delivery-plan';
        $blocks = $this->calendar($a); $blocks[4]=['start'=>null,'end'=>null,'hours'=>null];
        $this->actingAs($admin)->post($url.'/calendar',['version'=>0,'blocks'=>$blocks])->assertSessionHasNoErrors();
        $this->post($url.'/calendar',['version'=>0,'blocks'=>$this->calendar($a)])->assertSessionHasErrors('version');
        $this->post($url.'/approve',$this->checks(1))->assertSessionHasErrors('version');
        $this->actingAs($reviewer)->post($url.'/approve',['version'=>1])->assertSessionHasErrors();
        $this->post($url.'/approve',$this->checks(1))->assertSessionHasErrors('version');
        $this->assertNull(DeliveryPlan::approved($a));
    }

    public function test_old_approved_snapshot_survives_draft_amendments_and_profile_change_requires_reconciliation(): void
    {
        $f=$this->fixture();[$a,$admin,$reviewer,$tutor,$learner]=$f;$row=$this->approvePlan($f);$approved=$row->approved;
        $this->actingAs($admin)->post('/apprentices/'.$a->id.'/delivery-plan/details',[...$this->details(3),'source_ref'=>'Unapproved replacement'])->assertSessionHasNoErrors();
        $this->assertSame($approved,DeliveryPlan::approved($a));
        $this->actingAs($learner)->get('/apprentices/'.$a->id.'/delivery-plan')->assertSee('Fictional controlled plan v1')->assertDontSee('Unapproved replacement');
        $a->start_date=$a->start_date->addDay();$a->save();$this->assertNull(DeliveryPlan::approved($a));
        $this->actingAs($reviewer)->post('/apprentices/'.$a->id.'/delivery-plan/approve',$this->checks(4))->assertSessionHasErrors('version');
        $this->actingAs($admin)->post('/apprentices/'.$a->id.'/delivery-plan/calendar',['version'=>4,'blocks'=>$this->calendar($a)])->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('details',$row->fresh()->draft);
        $this->assertSame($approved,$row->fresh()->approved);
    }

    public function test_plan_scope_and_mutation_permissions_and_programme_guards(): void
    {
        [$a,$admin,$reviewer,$tutor,$learner]=$this->fixture();$url='/apprentices/'.$a->id.'/delivery-plan';
        foreach([$tutor,$learner,$this->actor('mentor')] as $u) {
            $this->actingAs($u)->post($url.'/calendar',['version'=>0,'blocks'=>$this->calendar($a)])->assertForbidden();
            $this->post($url.'/approve',$this->checks(0))->assertForbidden();
            $this->post($url.'/details',$this->details(0))->assertForbidden();
            $this->post($url.'/apply')->assertForbidden();
            $this->post($url.'/withdraw')->assertForbidden();
        }
        $this->actingAs($this->actor('tutor'))->get($url)->assertForbidden();
        $this->actingAs($this->actor('apprentice'))->get($url.'/approved.pdf')->assertForbidden();
        $a->programmeRecord()->update(['version'=>'unloaded']);
        $this->actingAs($admin)->post($url.'/calendar',['version'=>0,'blocks'=>$this->calendar($a)])->assertSessionHasErrors('version');
    }

    public function test_target_mismatch_and_unapproved_rpl_basis_cannot_be_applied(): void
    {
        [$a,$admin,$reviewer]=$this->fixture();$url='/apprentices/'.$a->id.'/delivery-plan';
        $this->actingAs($admin)->post($url.'/details',[...$this->details(0),'basis'=>'Current Autocare RPL assessment'])->assertSessionHasErrors('basis');
        $this->post($url.'/calendar',['version'=>0,'blocks'=>$this->calendar($a)])->assertSessionHasNoErrors();
        $this->post($url.'/details',[...$this->details(1),'target_hours'=>111])->assertSessionHasNoErrors();
        $this->actingAs($reviewer)->post($url.'/approve',$this->checks(2))->assertSessionHasErrors('version');
        $this->assertNull(DeliveryPlan::approved($a));
    }

    public function test_deadline_preview_is_version_bound_and_preserves_existing_dates_and_submitted_work(): void
    {
        $f=$this->fixture('panel');[$a,$admin,$reviewer]=$f;$row=$this->approvePlan($f);$url='/apprentices/'.$a->id.'/delivery-plan';
        $this->actingAs($admin)->post('/apprentices/'.$a->id.'/learning',['confirmed'=>1])->assertSessionHasNoErrors();
        $tasks=LearningTask::where('apprentice_id',$a->id)->orderBy('block')->orderBy('reference')->get();
        $tasks[0]->due_date='2027-12-31';$tasks[0]->save();
        $tasks[1]->status='Approved';$tasks[1]->save();
        $tasks[2]->status='Awaiting marking';$tasks[2]->save();
        $before=$tasks->take(3)->map(fn($t)=>$t->fresh()->getAttributes())->all();
        $binding=DeliveryPlan::taskBinding($a);$this->assertCount(47,DeliveryPlan::preview($a,$row->approved));
        $tasks[4]->version++;$tasks[4]->save();
        $payload=['version'=>3,'approval_hash'=>$row->approved['hash'],'task_binding'=>$binding,'confirmed'=>1];
        $this->post($url.'/apply',$payload)->assertSessionHasErrors('version');
        $payload['task_binding']=DeliveryPlan::taskBinding($a);
        $this->post($url.'/apply',$payload)->assertSessionHasNoErrors();
        $this->assertSame($before,$tasks->take(3)->map(fn($t)=>$t->fresh()->getAttributes())->all());
        $this->assertSame($row->approved['blocks'][0]['end'],$tasks[4]->fresh()->due_date->toDateString());
        $deadlineHistory=$tasks[4]->fresh()->deadline_history;
        $this->assertCount(1,$deadlineHistory);$this->assertSame('approved_plan',$deadlineHistory[0]['origin']);
        $this->assertSame($admin->id,$deadlineHistory[0]['by']);$this->assertNull($deadlineHistory[0]['before']);
        $this->assertSame(['plan_id'=>$row->id,'approved_hash'=>$row->approved['hash']],$deadlineHistory[0]['source']);
        $this->assertNull($tasks[0]->fresh()->deadline_history);$this->assertNull($tasks[1]->fresh()->deadline_history);

        $this->assertSame(4,$row->fresh()->version);$this->assertCount(0,DeliveryPlan::preview($a,$row->fresh()->approved));
        $this->assertDatabaseCount('learning_tasks',50);
        $this->post($url.'/apply',[...$payload,'version'=>4,'task_binding'=>DeliveryPlan::taskBinding($a)])->assertSessionHasNoErrors();
        $this->assertSame(4,$row->fresh()->version);
    }

    public function test_approval_and_deadline_audit_failures_roll_back_every_change(): void
    {
        $f=$this->fixture();[$a,$admin,$reviewer]=$f;$url='/apprentices/'.$a->id.'/delivery-plan';
        $this->actingAs($admin)->post($url.'/calendar',['version'=>0,'blocks'=>$this->calendar($a)]);
        $this->post($url.'/details',$this->details());
        \Tests\Support\AuditFault::install('fail_plan_event','learning_events','Fictional audit failure');
        $this->actingAs($reviewer)->post($url.'/approve',$this->checks(2))->assertStatus(500);
        $row=Plan::where('apprentice_id',$a->id)->first();$this->assertFalse($row->active);$this->assertNull($row->approved);$this->assertSame(2,$row->version);
        DB::statement('DROP TRIGGER fail_plan_event');
        $this->post($url.'/approve',$this->checks(2))->assertSessionHasNoErrors();$row->refresh();
        $this->actingAs($admin)->post('/apprentices/'.$a->id.'/learning',['confirmed'=>1]);
        $before=LearningTask::where('apprentice_id',$a->id)->get()->map->getAttributes()->all();
        \Tests\Support\AuditFault::install('fail_plan_event','learning_events','Fictional audit failure');
        $this->post($url.'/apply',['version'=>3,'approval_hash'=>$row->approved['hash'],'task_binding'=>DeliveryPlan::taskBinding($a),'confirmed'=>1])->assertStatus(500);
        $this->assertSame($before,LearningTask::where('apprentice_id',$a->id)->get()->map->getAttributes()->all());
        $this->assertSame(3,$row->fresh()->version);
    }

    public function test_withdrawal_keeps_snapshots_and_removes_current_pdf(): void
    {
        $f=$this->fixture();[$a,$admin]=$f;$row=$this->approvePlan($f);$before=$row->approved;
        $this->actingAs($admin)->post('/apprentices/'.$a->id.'/delivery-plan/withdraw',['version'=>3,'reason'=>'Controlled source needs checking'])->assertSessionHasNoErrors();
        $this->assertNull(DeliveryPlan::approved($a));$this->assertSame($before,$row->fresh()->approved);
        $this->get('/apprentices/'.$a->id.'/delivery-plan/approved.pdf')->assertNotFound();
    }

    private function completeReviewFields(): array
    {
        return ['reviewDate'=>today()->toDateString(),'trainingFrom'=>today()->subDays(70)->toDateString(),'mentor'=>'Fictional Mentor','tutor'=>'Fictional Tutor','employerRep'=>'Fictional Employer','method'=>'Face to face','attendance'=>'Present','judgement'=>'Behind plan','previousCheck'=>'Existing action plan checked','planChange'=>'No','summary'=>'Fictional three-way discussion recorded','learning'=>'Actual training discussed','progressDiscussion'=>'Evidence and remaining gaps discussed','previousNotes'=>'N/A - no outstanding previous actions','planChangeNotes'=>'N/A - no amendment','sharedSupport'=>'N/A','absence'=>'N/A','newNeeds'=>'No','trainingChecked'=>1,'targetsAgreed'=>1,'providerPrepared'=>1];
    }

    public function test_reviews_link_fixed_approved_plan_then_freeze_and_reopen_without_completing_review(): void
    {
        $f=$this->fixture();[$a,$admin,$reviewer,$tutor]=$f;$plan=$this->approvePlan($f);
        $this->actingAs($tutor)->post('/apprentices/'.$a->id.'/reviews',['fields'=>$this->completeReviewFields()])->assertSessionHasNoErrors();
        $review=ProgressReviewDraft::where('apprentice_id',$a->id)->firstOrFail();$url='/apprentices/'.$a->id.'/reviews/'.$review->id;
        $this->assertSame($plan->approved['hash'],$review->source_snapshot['delivery_plan']['hash']);
        $snapshot=$review->source_snapshot;
        $this->post($url.'/ready',['version'=>1,'confirmed'=>1])->assertSessionHasNoErrors();
        $this->assertSame('Ready for signatures',$review->fresh()->state);$this->assertSame($snapshot,$review->fresh()->source_snapshot);
        $this->post($url,['version'=>2,'fields'=>['summary'=>'Forbidden overwrite']])->assertSessionHasErrors('version');
        $this->post($url.'/reopen',['version'=>1,'reason'=>'Correct the discussion'])->assertSessionHasErrors('version');
        $this->post($url.'/reopen',['version'=>2,'reason'=>'Correct the discussion'])->assertSessionHasNoErrors();
        $this->assertSame('Draft',$review->fresh()->state);$this->assertCount(2,$review->fresh()->history);
        $this->assertDatabaseMissing('learning_events',['event'=>'review_completed']);
        $pdf=$this->get($url.'/draft.pdf')->assertOk();
        if($dir=getenv('GTA_QA_EXPORT_DIR')) { file_put_contents($dir.'/delivery-review.pdf',$pdf->getContent()); }
        $this->actingAs($admin);$pdf=$this->get('/apprentices/'.$a->id.'/delivery-plan/approved.pdf')->assertOk();
        if($dir=getenv('GTA_QA_EXPORT_DIR')) { file_put_contents($dir.'/individual-delivery-plan.pdf',$pdf->getContent()); }
    }

    public function test_reviews_reject_missing_sections_no_plan_and_withdrawn_plan_and_other_apprentice(): void
    {
        $f=$this->fixture();[$a,$admin,$reviewer,$tutor,$learner]=$f;
        $this->actingAs($tutor)->post('/apprentices/'.$a->id.'/reviews',['fields'=>$this->completeReviewFields()]);
        $review=ProgressReviewDraft::first();$url='/apprentices/'.$a->id.'/reviews/'.$review->id;
        $this->post($url.'/ready',['version'=>1,'confirmed'=>1])->assertSessionHasErrors('version');
        $plan=$this->approvePlan($f);
        $this->actingAs($tutor)->post($url,['version'=>1,'fields'=>['reviewDate'=>today()->toDateString(),'summary'=>'Partial']]);
        $this->post($url.'/ready',['version'=>2,'confirmed'=>1])->assertSessionHasErrors('version');
        $this->post($url,['version'=>2,'fields'=>$this->completeReviewFields()])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/apprentices/'.$a->id.'/delivery-plan/withdraw',['version'=>3,'reason'=>'Source changed']);
        $this->actingAs($tutor)->post($url.'/ready',['version'=>3,'confirmed'=>1])->assertSessionHasErrors('version');
        $this->actingAs($learner)->post($url.'/ready',['version'=>3,'confirmed'=>1])->assertForbidden();
        $other=Apprentice::factory()->create(['tutor_id'=>$tutor->id]);
        $this->actingAs($tutor)->post('/apprentices/'.$other->id.'/reviews/'.$review->id.'/ready',['version'=>3,'confirmed'=>1])->assertNotFound();
    }
    public function test_review_freeze_audit_failure_preserves_draft_and_exact_snapshot(): void
    {
        $f=$this->fixture();[$a,$admin,$reviewer,$tutor]=$f;$this->approvePlan($f);
        $this->actingAs($tutor)->post('/apprentices/'.$a->id.'/reviews',['fields'=>$this->completeReviewFields()]);
        $review=ProgressReviewDraft::first();$before=$review->getAttributes();
        \Tests\Support\AuditFault::install('reject_review_event','learning_events','Fictional audit failure');
        $this->post('/apprentices/'.$a->id.'/reviews/'.$review->id.'/ready',['version'=>1,'confirmed'=>1])->assertStatus(500);
        $this->assertSame($before,$review->fresh()->getAttributes());
    }

    public function test_review_against_old_plan_must_be_resaved_after_independent_reapproval(): void
    {
        $f=$this->fixture();[$a,$admin,$reviewer,$tutor]=$f;$plan=$this->approvePlan($f);
        $this->actingAs($tutor)->post('/apprentices/'.$a->id.'/reviews',['fields'=>$this->completeReviewFields()]);
        $review=ProgressReviewDraft::first();$old=$review->source_snapshot;
        $this->actingAs($admin)->post('/apprentices/'.$a->id.'/delivery-plan/details',[...$this->details(3),'source_ref'=>'Fictional controlled plan v2']);
        $this->actingAs($reviewer)->post('/apprentices/'.$a->id.'/delivery-plan/approve',$this->checks(4))->assertSessionHasNoErrors();
        $this->actingAs($tutor)->post('/apprentices/'.$a->id.'/reviews/'.$review->id.'/ready',['version'=>1,'confirmed'=>1])->assertSessionHasErrors('version');
        $this->assertSame($old,$review->fresh()->source_snapshot);
    }

    public function test_obsolete_programme_does_not_prevent_withdrawing_an_old_approval(): void
    {
        $f=$this->fixture();[$a,$admin]=$f;$row=$this->approvePlan($f);
        $a->programmeRecord()->update(['active'=>false]);
        $this->actingAs($admin)->post('/apprentices/'.$a->id.'/delivery-plan/withdraw',['version'=>3,'reason'=>'Programme source was withdrawn'])->assertSessionHasNoErrors();
        $this->assertFalse($row->fresh()->active);$this->assertNotNull($row->fresh()->approved);
    }

}
