<?php
namespace Tests\Feature;
use App\Models\{Apprentice,ProgressReviewDraft,User};
use App\Support\ReviewPreparation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class ReviewPreparationTest extends TestCase
{
 use LazilyRefreshDatabase;
 private function fields():array {
  return ['reviewDate'=>'2026-11-01','trainingFrom'=>'2026-10-01','mentor'=>'Fictional Mentor','tutor'=>'Fictional Tutor','employerRep'=>'Fictional Employer','method'=>'Face to face','attendance'=>'Present','judgement'=>'Behind plan','previousCheck'=>'Existing action plan checked','planChange'=>'No','summary'=>'Fictional discussion','learning'=>'Learning checked','progressDiscussion'=>'Progress checked','previousNotes'=>'N/A','planChangeNotes'=>'N/A','sharedSupport'=>'N/A','absence'=>'N/A','newNeeds'=>'No','trainingChecked'=>1,'targetsAgreed'=>1,'providerPrepared'=>1];
 }
 private function fixture():array {
  $u=User::factory()->create(['role'=>'tutor']);$a=Apprentice::factory()->create(['tutor_id'=>$u->id]);$this->actingAs($u)->post('/apprentices/'.$a->id.'/reviews',['fields'=>['summary'=>'Fictional draft']])->assertSessionHasNoErrors();$r=ProgressReviewDraft::where('apprentice_id',$a->id)->firstOrFail();$r->fields=[];$r->save();return [$u,$a,$r];
 }
 public function test_empty_saved_draft_lists_every_pending_item_with_human_labels_and_sections():void {
  $r=ReviewPreparation::inspect([],[],null);$this->assertFalse($r['ready']);$this->assertCount(22,$r['pending']);$labels=array_column($r['pending'],'label');$this->assertContains('Actual meeting date',$labels);$this->assertContains('Discussion summary',$labels);$this->assertContains('Saved draft linked to the current approved delivery plan',$labels);
  $this->assertSame(array_keys(ReviewPreparation::SECTIONS),array_values(array_unique(array_column($r['pending'],'section'))));
 }
 public function test_complete_saved_entries_with_matching_plan_pass_without_changing_inputs():void {
  $fields=$this->fields();$snapshot=['delivery_plan'=>['hash'=>str_repeat('a',64)]];$before=$fields;$r=ReviewPreparation::inspect($fields,$snapshot,['hash'=>str_repeat('a',64)]);$this->assertTrue($r['ready']);$this->assertSame([],$r['pending']);$this->assertSame($before,$fields);ReviewPreparation::requireReady($fields,$snapshot,['hash'=>str_repeat('a',64)]);
 }
 public function test_existing_readiness_rule_parity_for_missing_fields_booleans_previous_check_and_plan():void {
  $good=$this->fields();$required=['reviewDate','trainingFrom','mentor','tutor','employerRep','method','attendance','judgement','previousCheck','planChange','summary','learning','progressDiscussion','previousNotes','planChangeNotes','sharedSupport','absence','newNeeds'];$cases=[$good];
  foreach($required as$key){$f=$good;$f[$key]=' ';$cases[]=$f;}
  foreach(['trainingChecked','targetsAgreed','providerPrepared']as$key){$f=$good;$f[$key]=false;$cases[]=$f;}$cases[]=[...$good,'previousCheck'=>'Unable to check'];
  foreach($cases as$f){foreach([null,['hash'=>'same'],['hash'=>'different']]as$plan){$old=true;foreach($required as$key){if(!filled($f[$key]??null)){$old=false;}}$old=$old&&($f['trainingChecked']??false)&&($f['targetsAgreed']??false)&&($f['providerPrepared']??false)&&$f['previousCheck']!=='Unable to check'&&$plan!==null&&$plan['hash']==='same';$this->assertSame((bool)$old,ReviewPreparation::inspect($f,['delivery_plan'=>['hash'=>'same']],$plan)['ready']);}}
 }
 public function test_saved_draft_view_links_sections_and_is_business_read_only():void {
  [$u,$a,$r]=$this->fixture();$before=DB::table('progress_review_drafts')->where('id',$r->id)->first();
  $response=$this->actingAs($u)->get('/apprentices/'.$a->id.'/reviews/'.$r->id)->assertOk()->assertSee('Saved draft preparation check')->assertSee('Actual meeting date')->assertSee('Discussion summary')->assertSee('href="#review-1"',false)->assertSee('href="#review-6"',false)->assertSee('save the draft')->assertSee('ask for clarification');
  $this->assertEquals($before,DB::table('progress_review_drafts')->where('id',$r->id)->first());$this->assertSame([],$r->fresh()->fields);$this->assertFalse($response->viewData('preparation')['ready']);
 }
 public function test_ready_post_reports_all_saved_pending_items_and_ignores_forged_completed_fields():void {
  [$u,$a,$r]=$this->fixture();$this->actingAs($u)->post('/apprentices/'.$a->id.'/reviews/'.$r->id.'/ready',['version'=>1,'confirmed'=>1,'fields'=>$this->fields(),'preparation'=>['ready'=>true]])->assertSessionHasErrors('version');
  $error=session('errors')->first('version');$this->assertStringContainsString('Actual meeting date',$error);$this->assertStringContainsString('Discussion summary',$error);$this->assertStringContainsString('current approved delivery plan',$error);$this->assertSame('Draft',$r->fresh()->state);$this->assertSame(1,$r->fresh()->version);
 }
 public function test_unchecked_previous_actions_and_plan_drift_are_visible_as_pending():void {
  $f=$this->fields();$f['previousCheck']='Unable to check';$f['targetsAgreed']=false;$r=ReviewPreparation::inspect($f,['delivery_plan'=>['hash'=>'old']],['hash'=>'new']);$this->assertSame(['previousCheck','targetsAgreed','currentPlan'],array_column($r['pending'],'key'));$this->assertFalse($r['ready']);
 }
 public function test_new_and_frozen_records_do_not_show_an_editable_saved_draft_checklist():void {
  [$u,$a,$r]=$this->fixture();$this->actingAs($u)->get('/apprentices/'.$a->id.'/reviews/new')->assertOk()->assertSee('Save this draft to see its preparation checklist')->assertDontSee('Saved draft preparation check');
  $r->state='Ready for signatures';$r->save();$this->get('/apprentices/'.$a->id.'/reviews/'.$r->id)->assertOk()->assertDontSee('Saved draft preparation check')->assertSee('Reopen as draft');
 }
 public function test_no_extra_access_for_learner_employer_or_unrelated_tutor_and_mentor_scope_remains():void {
  [$u,$a,$r]=$this->fixture();foreach(['apprentice','employer','tutor','mentor']as$role){$other=User::factory()->create(['role'=>$role]);$this->actingAs($other)->get('/apprentices/'.$a->id.'/reviews/'.$r->id)->assertForbidden();}
  $mentor=User::factory()->create(['role'=>'mentor']);$a->mentor_id=$mentor->id;$a->save();$this->actingAs($mentor)->get('/apprentices/'.$a->id.'/reviews/'.$r->id)->assertOk()->assertSee('Saved draft preparation check');
 }
}
