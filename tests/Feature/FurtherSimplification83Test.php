<?php
namespace Tests\Feature;

use App\Models\{Apprentice,AttendanceMark,IndividualDeliveryPlan,ProgressReviewDraft,TrainingSession,TutorGroup,User};
use App\Support\{DeliveryPlan,SupportRegister71};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\{DB,Mail};
use Tests\TestCase;

final class FurtherSimplification83Test extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void {parent::setUp();$this->travelTo(Carbon::parse('2026-11-24 17:00'));}

    private function fixture(): array
    {
        $u=User::factory()->create(['role'=>'tutor','active'=>true]);
        $a=Apprentice::factory()->create(['tutor_id'=>$u->id,'start_date'=>'2026-01-01']);
        $plan=new IndividualDeliveryPlan(['apprentice_id'=>$a->id]);$plan->active=true;
        $plan->approved=['identity'=>DeliveryPlan::identity($a),'hash'=>str_repeat('a',64),'approved_at'=>now()->toIso8601String(),
            'blocks'=>[],'details'=>['source_ref'=>'Fictional test approval','target_hours'=>600]];$plan->draft=$plan->approved;$plan->history=[];$plan->save();
        $this->actingAs($u);
        return [$u,$a,$plan];
    }

    private function fields(): array
    {
        return ['reviewDate'=>'2026-11-24','trainingFrom'=>'2026-11-01','mentor'=>'Actual mentor','tutor'=>'Actual tutor','employerRep'=>'Actual representative',
            'method'=>'Face to face','attendance'=>'Present','absence'=>'No absence','judgement'=>'On track','learning'=>'Checked learning discussion',
            'progressDiscussion'=>'Checked against current plan','previousCheck'=>'Existing action plan checked','previousNotes'=>'Previous actions checked',
            'planChange'=>'No','planChangeNotes'=>'No change','newNeeds'=>'No','sharedSupport'=>'No shared support needed','summary'=>'Actual three-way discussion',
            'trainingChecked'=>1,'targetsAgreed'=>1,'providerPrepared'=>1,'safetyAnswer'=>'no'];
    }

    public function test_home_has_one_filter_form_that_keeps_scope_and_work_status_together(): void
    {
        [$u,$a]=$this->fixture();$g=TutorGroup::factory()->create(['tutor_id'=>$u->id]);$a->tutor_group_id=$g->id;$a->save();
        $page=$this->get(route('dashboard.index',['group'=>$g->id,'apprentice'=>$a->id,'priority'=>'checks']))->assertOk()->assertSee('filters applied');
        $dom=new \DOMDocument();@$dom->loadHTML($page->getContent());$xp=new \DOMXPath($dom);
        $this->assertSame(1,$xp->query('//main//form[.//select[@name="priority"] and .//select[@name="group"] and .//select[@name="apprentice"]]')->length);
        $this->assertSame(1,$xp->query('//main//select[@name="group"]')->length);
        $this->assertSame(1,$page->viewData('metrics')['apprentices']);
        $other=Apprentice::factory()->create();$this->get(route('dashboard.index',['apprentice'=>$other->id]))->assertNotFound();
    }

    public function test_archive_is_available_to_admin_but_does_not_compete_in_profile_header(): void
    {
        $u=User::factory()->create(['role'=>'admin']);$a=Apprentice::factory()->create();$page=$this->actingAs($u)->get(route('apprentices.show',$a))->assertOk();
        $dom=new \DOMDocument();@$dom->loadHTML($page->getContent());$xp=new \DOMXPath($dom);$url=route('apprentices.close-down',$a);
        $this->assertSame(1,$xp->query('//main//a[@href="'.$url.'"]')->length);
        $this->assertSame(0,$xp->query('//main/header//a[@href="'.$url.'"]')->length);
    }

    public function test_confirmed_register_shows_recorded_hours_without_expanding_every_edit_field(): void
    {
        [$u,$a]=$this->fixture();$g=TutorGroup::factory()->create(['tutor_id'=>$u->id]);$a->tutor_group_id=$g->id;$a->save();
        $s=TrainingSession::factory()->create(['tutor_group_id'=>$g->id,'members'=>[$a->id],'session_date'=>'2026-11-23']);
        AttendanceMark::create(['training_session_id'=>$s->id,'apprentice_id'=>$a->id,'code'=>'O','arrival'=>'09:00','departure'=>'16:00','otj_confirmed'=>true,'otj_minutes'=>360,'recorded_by'=>$u->id]);
        $page=$this->get(route('attendance.show',$s))->assertOk()->assertSee('6.00 hours');
        $dom=new \DOMDocument();@$dom->loadHTML($page->getContent());$xp=new \DOMXPath($dom);
        $this->assertSame(1,$xp->query('//details[summary="Change times, OTJ minutes or notes" and not(@open)]')->length);
        $this->assertDatabaseCount('attendance_marks',1);
        $this->from(route('attendance.show',$s))->post(route('attendance.save',$s),['version'=>$s->version,'marks'=>[$a->id=>['code'=>'O','arrival'=>'invalid']]])->assertSessionHasErrors();
        $error=$this->get(route('attendance.show',$s))->assertOk();$doc=new \DOMDocument();@$doc->loadHTML($error->getContent());
        $this->assertSame(1,(new \DOMXPath($doc))->query('//details[summary="Change times, OTJ minutes or notes" and @open]')->length);
    }

    public function test_meeting_shows_action_plan_with_marker_reviews_closed_and_does_not_save(): void
    {
        $u=User::factory()->create(['role'=>'admin']);$a=Apprentice::factory()->create();
        $s=SupportRegister71::addMarker($u,$a,['code'=>'IM34','interval_key'=>'six_monthly','owner_id'=>$u->id]);
        $page=$this->actingAs($u)->get(route('support.worklist',['meeting'=>$a->id]))->assertOk()->assertSee('Change review frequency');
        $dom=new \DOMDocument();@$dom->loadHTML($page->getContent());$xp=new \DOMXPath($dom);
        $this->assertSame(1, $xp->query('//details[not(@open) and summary="Record review"]')->length);
        $this->assertSame(1, $xp->query('//section[@id="support-actions"]//form//input[@name="measure" and @required]')->length);
        $this->assertSame(1,$xp->query('//details[summary="Change review frequency" and not(@open)]')->length);
        $this->assertFalse($s->fresh()->first_review_completed);
    }

    public function test_one_post_can_save_and_prepare_a_checked_new_review_without_signing_or_mail(): void
    {
        [$u,$a,$plan]=$this->fixture();Mail::fake();
        $this->post(route('reviews.store',$a),['fields'=>$this->fields(),'after_save'=>'prepare','prepare_confirmed'=>1,'prepare_plan_hash'=>$plan->approved['hash']])->assertSessionHasNoErrors()->assertRedirect();
        $row=ProgressReviewDraft::sole();$this->assertSame('Ready for signatures',$row->state);$this->assertSame(2,$row->version);
        $this->assertSame('Actual three-way discussion',$row->fields['summary']);
        $this->assertSame($plan->approved['hash'],$row->source_snapshot['delivery_plan']['hash']);
        $this->assertDatabaseHas('learning_events',['event'=>'ready_for_signatures','record_id'=>$row->id]);
        $this->assertDatabaseCount('signed_review_records',0);Mail::assertNothingSent();
        $this->post(route('reviews.update',[$a,$row]),['version'=>$row->version,'fields'=>$this->fields(),'after_save'=>'prepare','prepare_confirmed'=>1,'prepare_plan_hash'=>$plan->approved['hash']])->assertSessionHasErrors();
        $this->assertDatabaseCount('progress_review_drafts',1);
    }

    public function test_preparing_an_existing_draft_preserves_old_fields_snapshot_and_history(): void
    {
        [$u,$a,$plan]=$this->fixture();$this->post(route('reviews.store',$a),['fields'=>['summary'=>'Original draft']])->assertSessionHasNoErrors();
        $row=ProgressReviewDraft::sole();$old=$row->source_snapshot;
        $this->post(route('reviews.update',[$a,$row]),['version'=>$row->version,'fields'=>$this->fields(),'after_save'=>'prepare','prepare_confirmed'=>1,'prepare_plan_hash'=>$plan->approved['hash']])->assertSessionHasNoErrors();
        $row->refresh();$this->assertSame('Ready for signatures',$row->state);$this->assertSame('Original draft',$row->history[0]['fields']['summary']);$this->assertSame($old,$row->history[0]['source_snapshot']);
    }

    public function test_signoff_rejects_a_register_updated_after_the_tutor_opened_the_draft(): void
    {
        [$u,$a,$plan] = $this->fixture();
        $fields = [...$this->fields(), 'format83'=>'simple83', 'checked83'=>1];
        $this->post(route('reviews.store', $a), ['fields'=>$fields])->assertSessionHasNoErrors();
        $draft = ProgressReviewDraft::sole();
        $url = route('reviews.edit', [$a, $draft]);
        $shown = $this->get($url)->assertOk()->viewData('factsHash');
        $group = TutorGroup::factory()->create(['tutor_id'=>$u->id]);
        $register = TrainingSession::factory()->create([
            'tutor_group_id'=>$group->id, 'members'=>[$a->id], 'session_date'=>'2026-11-23',
        ]);
        $this->post(route('attendance.save', $register), [
            'version'=>$register->version,'delivered_learning'=>'Fictional confirmed workshop activity',
            'marks'=>[$a->id=>['code'=>'O','arrival'=>'09:00','departure'=>'16:00','otj_confirmed'=>1]],
        ])->assertSessionHasNoErrors();
        $post = ['version'=>$draft->version,'fields'=>$fields,'after_save'=>'signoff','prepare_plan_hash'=>$plan->approved['hash']];
        $this->from($url)->post(route('reviews.update', [$a, $draft]), $post + ['review_facts_hash'=>$shown])
            ->assertSessionHasErrors('prepare_confirmed');
        $this->assertSame('Draft', $draft->fresh()->state);
        $current = $this->get($url)->assertOk()->viewData('factsHash');
        $this->assertNotSame($shown, $current);
        $this->post(route('reviews.update', [$a, $draft]), $post + ['review_facts_hash'=>$current])
            ->assertRedirect(route('electronic-reviews.prepare-form', $draft))->assertSessionHasNoErrors();
        $this->assertSame('Ready for signatures', $draft->fresh()->state);
    }

    public function test_missing_confirmation_or_incomplete_review_does_not_create_or_freeze_records(): void
    {
        [$u,$a,$plan]=$this->fixture();
        $this->post(route('reviews.store',$a),['fields'=>$this->fields(),'after_save'=>'prepare','prepare_plan_hash'=>$plan->approved['hash']])->assertSessionHasErrors('prepare_confirmed');
        $this->assertDatabaseCount('progress_review_drafts',0);
        $this->post(route('reviews.store',$a),['fields'=>['summary'=>'Incomplete'],'after_save'=>'prepare','prepare_confirmed'=>1,'prepare_plan_hash'=>$plan->approved['hash']])->assertSessionHasErrors();
        $this->assertDatabaseCount('progress_review_drafts',0);$this->assertDatabaseCount('learning_events',0);
        $this->post(route('reviews.store',$a),['fields'=>['summary'=>'Still allowed as a draft'],'after_save'=>'stay'])->assertSessionHasNoErrors();
        $this->assertSame('Draft',ProgressReviewDraft::sole()->state);
    }

    public function test_plan_drift_stale_versions_and_unrelated_actors_cannot_bypass_preparation_checks(): void
    {
        [$u,$a,$plan]=$this->fixture();$this->post(route('reviews.store',$a),['fields'=>['summary'=>'Keep this draft']])->assertSessionHasNoErrors();
        $row=ProgressReviewDraft::sole();$before=$row->getAttributes();$approved=$plan->approved;$approved['hash']=str_repeat('b',64);$plan->approved=$approved;$plan->save();
        $payload=['version'=>$row->version,'fields'=>$this->fields(),'after_save'=>'prepare','prepare_confirmed'=>1,'prepare_plan_hash'=>$approved['hash']];
        $this->post(route('reviews.update',[$a,$row]),$payload)->assertSessionHasErrors('prepare_confirmed');$this->assertSame($before,$row->fresh()->getAttributes());
        $this->post(route('reviews.update',[$a,$row]),[...$payload,'version'=>999])->assertSessionHasErrors('version');
        $this->actingAs(User::factory()->create(['role'=>'tutor']))->post(route('reviews.update',[$a,$row]),$payload)->assertForbidden();
    }

    public function test_failed_preparation_audit_rolls_back_the_draft_and_both_events(): void
    {
        [$u,$a,$plan]=$this->fixture();
        DB::unprepared("CREATE TRIGGER fail_prepare83 BEFORE INSERT ON learning_events WHEN NEW.event = 'ready_for_signatures' BEGIN SELECT RAISE(FAIL, 'Synthetic audit failure'); END");
        $this->withoutExceptionHandling();
        try {
            $this->post(route('reviews.store',$a),['fields'=>$this->fields(),'after_save'=>'prepare','prepare_confirmed'=>1,'prepare_plan_hash'=>$plan->approved['hash']]);
            $this->fail('Expected audit failure');
        } catch(\Illuminate\Database\QueryException $error) {$this->assertStringContainsString('Synthetic audit failure',$error->getMessage());}
        finally{DB::unprepared('DROP TRIGGER fail_prepare83');}
        $this->assertDatabaseCount('progress_review_drafts',0);$this->assertDatabaseCount('learning_events',0);
    }
}
