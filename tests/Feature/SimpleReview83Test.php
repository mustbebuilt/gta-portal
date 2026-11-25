<?php
namespace Tests\Feature;

use App\Models\{Apprentice,IndividualDeliveryPlan,ProgressReviewDraft,SmartAction,User};
use App\Support\{DeliveryPlan,ElectronicReviews,ReviewPreparation};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class SimpleReview83Test extends TestCase
{
    use LazilyRefreshDatabase;
    protected function setUp():void {parent::setUp();$this->travelTo(Carbon::parse('2026-11-24 17:00'));}
    private function fixture():array {
        $u=User::factory()->create(['role'=>'tutor']);$a=Apprentice::factory()->create(['tutor_id'=>$u->id,'start_date'=>'2026-01-01']);
        $p=new IndividualDeliveryPlan(['apprentice_id'=>$a->id]);$p->active=true;$p->approved=['identity'=>DeliveryPlan::identity($a),'hash'=>str_repeat('c',64),'approved_at'=>now()->toIso8601String(),'blocks'=>[], 'details'=>['source_ref'=>'Fictional approved plan','target_hours'=>600]];$p->draft=$p->approved;$p->history=[];$p->save();
        $this->actingAs($u);return [$u,$a,$p];
    }
    private function fields():array {return ['format83'=>'simple83','reviewDate'=>'2026-11-24','trainingFrom'=>'2026-11-01','mentor'=>'Actual mentor','tutor'=>'Actual tutor','employerRep'=>'Actual employer representative','method'=>'Face to face','attendance'=>'Present','judgement'=>'On track','summary'=>'Actual learning, progress against the plan and apprentice/employer views.','planChange'=>'No','newNeeds'=>'No','safetyAnswer'=>'no','checked83'=>1];}

    public function test_review_has_four_sections_and_one_main_discussion_box_without_repeated_required_notes():void {
        [$u,$a]=$this->fixture();$page=$this->get(route('reviews.create',$a))->assertOk()->assertSee('1. Progress')->assertSee('2. Discussion')->assertSee('3. Targets')->assertSee('4. Finish');
        $dom=new \DOMDocument();@$dom->loadHTML($page->getContent());$xp=new \DOMXPath($dom);
        $this->assertSame(1,$xp->query('//textarea[@name="fields[summary]"]')->length);
        foreach(['learning','progressDiscussion','previousNotes','sharedSupport'] as $key)$this->assertSame(0,$xp->query('//textarea[@name="fields['.$key.']"]')->length);
        $this->assertSame(1,$xp->query('//input[@type="checkbox" and @name="fields[checked83]"]')->length);
        $this->assertDatabaseCount('progress_review_drafts',0);
    }
    public function test_single_checked_short_review_prepares_without_fabricated_na_notes_or_signatures():void {
        [$u,$a,$p]=$this->fixture();Mail::fake();
        $this->post(route('reviews.store',$a),['fields'=>$this->fields(),'after_save'=>'prepare','prepare_plan_hash'=>$p->approved['hash']])->assertSessionHasNoErrors();
        $r=ProgressReviewDraft::sole();$this->assertSame('Ready for signatures',$r->state);
        foreach(['learning','progressDiscussion','previousNotes','sharedSupport','previousCheck'] as $key)$this->assertArrayNotHasKey($key,$r->fields);
        $this->assertTrue((bool)$r->fields['trainingChecked']);$this->assertTrue((bool)$r->fields['targetsAgreed']);
        $this->assertDatabaseCount('signed_review_records',0);$this->assertDatabaseCount('smart_actions',0);Mail::assertNothingSent();
        if(getenv('GTA_SIMPLE_REVIEW_READY_PDF'))file_put_contents(getenv('GTA_SIMPLE_REVIEW_READY_PDF'),$this->get(route('reviews.pdf',[$a,$r]))->assertOk()->getContent());
        $this->get(route('reviews.edit',[$a,$r]))->assertOk()->assertSee('Participant signatures');
    }
    public function test_review_continues_to_signoff_with_a_paper_option_and_no_automatic_release(): void
    {
        [$tutor, $person, $plan] = $this->fixture();
        Mail::fake();
        $data = ['fields' => $this->fields(), 'after_save' => 'signoff', 'prepare_plan_hash' => $plan->approved['hash']];
        $this->get(route('reviews.create', $person))->assertOk()->assertSee('Save and continue to sign-off');
        $this->post(route('reviews.store', $person), [...$data, 'fields' => [...$data['fields'], 'checked83' => 0]])
            ->assertSessionHasErrors('prepare_confirmed');
        $this->assertDatabaseCount('progress_review_drafts', 0);
        $response = $this->post(route('reviews.store', $person), $data)->assertSessionHasNoErrors();
        $review = ProgressReviewDraft::sole();
        $signoff = route('electronic-reviews.prepare-form', $review);
        $response->assertRedirect($signoff);
        $this->get($signoff)->assertOk()->assertSee('Review sign-off')->assertSee('Sign on paper')
            ->assertSee(route('reviews.signed.form', $review))->assertDontSee('Prepare private preview');
        $this->assertSame('Ready for signatures', $review->state);
        $this->assertDatabaseCount('electronic_review_packets', 0);
        $this->assertDatabaseCount('signed_review_records', 0);
        Mail::assertNothingSent();
    }

    public function test_empty_judgement_and_unchecked_confirmation_do_not_prepare_even_with_forged_legacy_flags():void {
        [$u,$a,$p]=$this->fixture();$f=$this->fields();$f['checked83']=0;$f['trainingChecked']=$f['targetsAgreed']=$f['providerPrepared']=1;
        $this->post(route('reviews.store',$a),['fields'=>$f,'after_save'=>'prepare','prepare_plan_hash'=>$p->approved['hash'],'prepare_confirmed'=>1])->assertSessionHasErrors('prepare_confirmed');
        $this->assertDatabaseCount('progress_review_drafts',0);
        $f=$this->fields();unset($f['judgement']);$this->post(route('reviews.store',$a),['fields'=>$f,'after_save'=>'prepare','prepare_plan_hash'=>$p->approved['hash']])->assertSessionHasErrors();
        $this->assertDatabaseCount('progress_review_drafts',0);
    }
    public function test_absent_employer_and_plan_changes_require_relevant_details_only():void {
        [$u,$a,$p]=$this->fixture();$f=$this->fields();$f['attendance']='Absent — opportunity to contribute recorded';$f['planChange']='Yes';
        $this->post(route('reviews.store',$a),['fields'=>$f,'after_save'=>'prepare','prepare_plan_hash'=>$p->approved['hash']])->assertSessionHasErrors();$this->assertDatabaseCount('progress_review_drafts',0);
        $f['absence']='Employer contributed before the meeting by telephone.';$f['planChangeNotes']='Arrange agreed catch-up; record approved changes in the training plan.';
        $this->post(route('reviews.store',$a),['fields'=>$f,'after_save'=>'prepare','prepare_plan_hash'=>$p->approved['hash']])->assertSessionHasNoErrors();
        $this->assertSame('Ready for signatures',ProgressReviewDraft::sole()->state);
    }
    public function test_converting_unfrozen_draft_preserves_optional_notes_history_and_canonical_targets():void {
        [$u,$a]=$this->fixture();$action=SmartAction::factory()->create(['apprentice_id'=>$a->id]);
        $this->post(route('reviews.store',$a),['fields'=>['summary'=>'Earlier discussion','learning'=>'Keep earlier learning','progressDiscussion'=>'Keep original progress','previousNotes'=>'Keep earlier outcomes','sharedSupport'=>'PRIVATE-LEGACY-SUPPORT']])->assertSessionHasNoErrors();
        $r=ProgressReviewDraft::sole();$old=$r->fields;
        $this->post(route('reviews.update',[$a,$r]),['version'=>$r->version,'fields'=>$this->fields()])->assertSessionHasNoErrors();$r->refresh();
        $this->assertSame('Keep earlier learning',$r->fields['learning']);$this->assertSame('PRIVATE-LEGACY-SUPPORT',$r->fields['sharedSupport']);$this->assertSame($old,$r->history[0]['fields']);$this->assertDatabaseCount('smart_actions',1);
        $this->get(route('reviews.edit',[$a,$r]))->assertOk()->assertSee('Additional recorded notes')->assertSee($action->title);
    }
    public function test_frozen_legacy_source_keeps_legacy_format_and_is_not_migrated_by_reading():void {
        [$u,$a]=$this->fixture();$r=ProgressReviewDraft::create(['apprentice_id'=>$a->id,'author_id'=>$u->id,'fields'=>['summary'=>'Original frozen source'],'source_snapshot'=>\App\Support\ReviewSnapshot::capture($a,[])]);$r->state='Ready for signatures';$r->save();$before=$r->fresh()->getAttributes();
        $this->get(route('reviews.edit',[$a,$r]))->assertOk()->assertViewIs('reviews.form')->assertSee('Original frozen source');
        $this->assertSame($before,$r->fresh()->getAttributes());
    }
    public function test_frozen_short_review_shows_saved_targets_even_if_live_action_changes():void {
        [$u,$a,$p]=$this->fixture();$target=SmartAction::factory()->create(['apprentice_id'=>$a->id,'title'=>'Target at meeting']);
        $this->post(route('reviews.store',$a),['fields'=>$this->fields(),'after_save'=>'prepare','prepare_plan_hash'=>$p->approved['hash']])->assertSessionHasNoErrors();$r=ProgressReviewDraft::sole();
        $target->title='LATER LIVE TARGET';$target->save();
        $this->get(route('reviews.edit',[$a,$r]))->assertOk()->assertSee('Target at meeting')->assertDontSee('LATER LIVE TARGET');
        $this->assertSame('Target at meeting',$r->source_snapshot['action_plan'][0]['title']);
    }
    public function test_simple_readiness_checks_programme_dates_and_support_does_not_need_private_text():void {
        [$u,$a,$p]=$this->fixture();$f=$this->fields();$f['newNeeds']='Yes — follow up in restricted support record';
        $this->post(route('reviews.store',$a),['fields'=>$f,'after_save'=>'prepare','prepare_plan_hash'=>$p->approved['hash']])->assertSessionHasNoErrors();
        $r=ProgressReviewDraft::sole();$f['trainingFrom']='2025-01-01';$result=ReviewPreparation::inspect($f,$r->source_snapshot,$p->approved);$this->assertFalse($result['ready']);$this->assertContains('period83',array_column($result['pending'],'key'));
        $this->assertArrayNotHasKey('sharedSupport',$r->fields);
    }
    public function test_legacy_participant_fields_stay_identical_and_simple_display_omits_blank_optional_notes():void {
        $this->assertSame(ElectronicReviews::FIELDS,ElectronicReviews::displayFields(['fields'=>[]]));
        $fields=ElectronicReviews::displayFields(['layout83'=>'simple83','fields'=>['summary'=>'Actual discussion','sharedSupport'=>'PRIVATE']]);
        $this->assertArrayHasKey('summary',$fields);$this->assertArrayNotHasKey('learning',$fields);$this->assertArrayNotHasKey('previousNotes',$fields);$this->assertArrayNotHasKey('sharedSupport',$fields);
    }
    public function test_pdf_export_uses_saved_short_review_without_private_legacy_support_text():void {
        [$u,$a]=$this->fixture();$this->post(route('reviews.store',$a),['fields'=>$this->fields()])->assertSessionHasNoErrors();$r=ProgressReviewDraft::sole();$f=$r->fields;$f['sharedSupport']='PRIVATE-PDF-SUPPORT';$r->fields=$f;$r->save();
        $html=view('pdf.review-simple83',['review'=>$r,'apprentice'=>$a,'logo'=>''])->render();$this->assertStringContainsString('1. Progress',$html);$this->assertStringContainsString('Actual learning, progress',$html);$this->assertStringNotContainsString('PRIVATE-PDF-SUPPORT',$html);
        $response=$this->get(route('reviews.pdf',[$a,$r]))->assertOk()->assertHeader('Content-Type','application/pdf');$this->assertStringStartsWith('%PDF-',$response->getContent());
        if(getenv('GTA_SIMPLE_REVIEW_PDF'))file_put_contents(getenv('GTA_SIMPLE_REVIEW_PDF'),$response->getContent());
    }
}
