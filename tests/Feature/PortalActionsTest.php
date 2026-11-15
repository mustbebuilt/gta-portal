<?php
namespace Tests\Feature;
use App\Models\{Apprentice,User,SupportCapability,StaffDuty,ProgressReviewDraft};
use App\Support\{PortalActions as A,StaffDuties};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PortalActionsTest extends TestCase
{
 use LazilyRefreshDatabase;
 private function u(string $role='admin',array $more=[]):User{return User::factory()->create(['role'=>$role]+$more);}
 #[\PHPUnit\Framework\Attributes\TestWith(['admin'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['ceo'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['tutor'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['apprentice'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['employer'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['mentor'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['careers_adviser'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['functional_skills_tutor'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['inclusion_lead'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['inclusion_member'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['staff'])]
 public function test_role_gets_a_rendered_private_actions_page_without_any_new_records(string $role):void{
  $u=$this->u($role,['subject_scope'=>$role==='functional_skills_tutor'?'maths':null]);
  $this->actingAs($u)->get('/quick-actions')->assertOk()->assertSee('What would you like to do?')->assertHeader('Referrer-Policy','no-referrer');
  $this->assertDatabaseCount('apprentices',0);$this->assertDatabaseCount('progress_review_drafts',0);$this->assertDatabaseCount('support_plans',0);
 }
 public function test_apprentice_has_only_own_training_and_personal_actions():void{
  $u=$this->u('apprentice');$a=Apprentice::factory()->create(['user_id'=>$u->id]);$outside=Apprentice::factory()->create();
  $this->actingAs($u)->get('/quick-actions?task=training')->assertOk()->assertSee($a->reference)->assertDontSee($outside->reference);
  $this->get('/apprentices/'.$a->id.'/work-area')->assertOk()->assertSee('Open training blocks and tasks')->assertDontSee('Start or continue a support plan')->assertDontSee('Manage maths training and results');
  $this->get('/apprentices/'.$outside->id.'/work-area')->assertForbidden();$this->get('/quick-actions?task=markers')->assertForbidden();
 }
 public function test_employer_gets_shared_action_destination_without_any_apprentice_finder():void{
  $this->actingAs($this->u('employer'))->get('/quick-actions')->assertOk()->assertSee('Respond to employer actions')->assertDontSee('Choose apprentice next')->assertDontSee('Add a member of staff');
  $a=Apprentice::factory()->create();$this->get('/apprentices/'.$a->id.'/work-area')->assertForbidden();
 }
 public function test_tutor_cannot_find_outside_apprentices_by_name_or_reference_or_direct_url():void{
  $u=$this->u('tutor');$a=Apprentice::factory()->create(['tutor_id'=>$u->id]);$b=Apprentice::factory()->create(['last_name'=>'Private-Outsider']);
  $this->actingAs($u)->get('/quick-actions?task=reviews&search='.$b->reference)->assertOk()->assertViewHas('people',fn($p)=>$p->total()===0);
  $this->get('/apprentices/'.$b->id.'/work-area')->assertForbidden();
  $this->get('/apprentices/'.$a->id.'/work-area')->assertOk()->assertDontSee('Add or change inclusion markers')->assertDontSee('Record a careers discussion');
 }
 public function test_careers_scope_is_current_duty_and_assignment_not_plain_tutor_role():void{
  $u=$this->u('careers_adviser');$a=Apprentice::factory()->create(['careers_adviser_id'=>$u->id]);$b=Apprentice::factory()->create();
  $this->actingAs($u)->get('/quick-actions?task=careers')->assertOk()->assertSee($a->reference)->assertDontSee($b->reference);
  $this->assertStringEndsWith('#careers-discussion-form',A::destination($u,$a,'careers'));
  $a->careers_adviser_id=null;$a->save();$this->get('/apprentices/'.$a->id.'/work-area')->assertForbidden();
 }
 public function test_added_careers_duty_is_respected_and_revocation_removes_access():void{
  $u=$this->u('staff');$admin=$this->u();StaffDuties::save($u,$admin,1,1,0,['careers_adviser'=>true]);
  $a=Apprentice::factory()->create(['careers_adviser_id'=>$u->id]);$this->actingAs($u->fresh())->withSession(['auth_version'=>$u->fresh()->auth_version])->get('/quick-actions?task=careers')->assertOk()->assertSee($a->reference);
  StaffDuty::whereKey($u->id)->update(['careers_adviser'=>false]);$this->get('/quick-actions?task=careers')->assertForbidden();
 }
 public function test_mentor_only_gets_assigned_review_actions():void{
  $u=$this->u('mentor');$a=Apprentice::factory()->create(['mentor_id'=>$u->id]);$b=Apprentice::factory()->create();
  $this->actingAs($u)->get('/quick-actions?task=reviews')->assertOk()->assertSee($a->reference)->assertDontSee($b->reference);
  $this->get('/apprentices/'.$a->id.'/work-area')->assertOk()->assertSee('Prepare or continue a progress review')->assertDontSee('Open training blocks and tasks');
 }
 public function test_subject_tutor_scope_does_not_confer_other_subject_or_training_actions():void{
  $u=$this->u('functional_skills_tutor',['subject_scope'=>'maths']);$a=Apprentice::factory()->create(['maths_tutor_id'=>$u->id]);$outside=Apprentice::factory()->create(['english_tutor_id'=>$u->id]);
  $this->actingAs($u)->get('/quick-actions?task=maths')->assertOk()->assertSee($a->reference)->assertDontSee($outside->reference);
  $this->get('/quick-actions?task=english')->assertForbidden();$this->get('/quick-actions?task=training')->assertForbidden();
  $this->assertStringContainsString('subject=maths',A::destination($u,$a,'maths'));
 }
 public function test_support_member_finder_matches_explicit_and_group_scope_and_hides_marker_action():void{
  $u=$this->u('staff');$a=Apprentice::factory()->create();$b=Apprentice::factory()->create();
  SupportCapability::create(['user_id'=>$u->id,'duty'=>'member','scope'=>'assigned','apprentice_ids'=>[$a->id],'group_ids'=>[]]);
  $this->actingAs($u)->get('/quick-actions?task=support')->assertOk()->assertSee($a->reference)->assertDontSee($b->reference);
  $this->get('/quick-actions?task=markers')->assertForbidden();$this->get('/apprentices/'.$a->id.'/work-area')->assertOk()->assertDontSee('Add or change inclusion markers');
  $this->assertDatabaseCount('support_concerns',0);
 }
 public function test_support_lead_can_open_marker_source_without_automatically_saving_it():void{
  $u=$this->u('inclusion_lead');$a=Apprentice::factory()->create();$this->actingAs($u)->get('/quick-actions?task=markers')->assertOk()->assertSee($a->reference);
  $this->assertStringEndsWith('#support-markers',A::destination($u,$a,'markers'));$this->assertDatabaseCount('marker_change_events',0);
 }
 public function test_read_only_support_group_assignment_matches_existing_access():void{
  $u=$this->u('staff');$g=\App\Models\TutorGroup::factory()->create();$a=Apprentice::factory()->create(['tutor_group_id'=>$g->id]);
  SupportCapability::create(['user_id'=>$u->id,'duty'=>'member','scope'=>'assigned','apprentice_ids'=>[],'group_ids'=>[$g->id]]);
  $this->assertSame(\App\Support\SupportAccess::team($u,$a),A::hasPerson($u,$a));$this->actingAs($u)->get('/quick-actions?task=support')->assertSee($a->reference);
 }
 public function test_existing_review_draft_is_continued_and_no_new_draft_is_created():void{
  $u=$this->u();$a=Apprentice::factory()->create();$draft=ProgressReviewDraft::factory()->create(['apprentice_id'=>$a->id,'author_id'=>$u->id]);
  $this->assertSame(route('reviews.edit',[$a,$draft]),A::destination($u,$a,'reviews'));
  $this->actingAs($u)->get('/apprentices/'.$a->id.'/work-area')->assertOk()->assertSee(route('reviews.edit',[$a,$draft]),false);$this->assertDatabaseCount('progress_review_drafts',1);
 }
 public function test_appointment_action_opens_existing_list_instead_of_creating_another_appointment():void{
  $u=$this->u();$a=Apprentice::factory()->create();$this->assertSame(route('review-appointments.index',['search'=>$a->reference]),A::destination($u,$a,'appointments'));$this->assertDatabaseCount('review_appointments',0);
 }
 public function test_search_is_literal_for_sql_wildcards_and_injection_strings():void{
  $u=$this->u();$a=Apprentice::factory()->create(['reference'=>'LITERAL_%!']);$other=Apprentice::factory()->create();
  $this->actingAs($u)->get('/quick-actions?task=training&search='.urlencode('_%!'))->assertOk()->assertSee($a->reference)->assertDontSee($other->reference)->assertViewHas('people',fn($p)=>$p->total()===1);
  $this->get('/quick-actions?task=training&search='.urlencode("' OR 1=1 --"))->assertOk()->assertViewHas('people',fn($p)=>$p->total()===0);
 }
 public function test_search_and_pagination_keep_task_and_scope():void{
  $u=$this->u('tutor');Apprentice::factory()->count(23)->create(['tutor_id'=>$u->id,'last_name'=>'Finder']);$outside=Apprentice::factory()->create(['last_name'=>'Finder']);
  $this->actingAs($u)->get('/quick-actions?task=training&search=Finder&page=2')->assertOk()->assertViewHas('people',fn($p)=>$p->total()===23&&count($p->items())===3)->assertDontSee($outside->reference)->assertSee('task=training',false);
 }
 public function test_search_filters_action_catalogue_and_escapes_output():void{
  $this->actingAs($this->u())->get('/quick-actions?q=support%20plan')->assertOk()->assertSee('Start or continue a support plan')->assertDontSee('Add a tutor group');
  $this->get('/quick-actions?q='.urlencode('<script>alert(1)</script>'))->assertOk()->assertDontSee('<script>alert(1)</script>',false)->assertSee('No matching actions');
 }
 #[\PHPUnit\Framework\Attributes\TestWith(['task[]=support'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['task=not-a-task'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['search[]=x'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['q[]=x'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['page=0'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['page=1000001'])]
 public function test_malformed_filters_are_rejected(string $query):void{$this->actingAs($this->u())->getJson('/quick-actions?'.$query)->assertUnprocessable();}
 public function test_fresh_role_is_used_even_if_caller_holds_an_old_admin_object():void{
  $u=$this->u();User::whereKey($u->id)->update(['role'=>'employer']);$fresh=A::actor($u);$this->assertFalse(A::allowed($fresh,'support'));$this->assertArrayNotHasKey('accounts',A::globals($fresh));
 }
 public function test_inactive_user_and_guest_cannot_open_actions():void{
  $this->get('/quick-actions')->assertRedirect('/login');$this->actingAs($this->u('tutor',['active'=>false]))->get('/quick-actions')->assertForbidden();
 }
 public function test_private_record_bodies_are_not_loaded_or_rendered_in_person_area():void{
  $u=$this->u('tutor');$a=Apprentice::factory()->create(['tutor_id'=>$u->id]);\App\Models\SupportConcern::factory()->create(['apprentice_id'=>$a->id,'initial_note'=>'PRIVATE-CASE-44']);
  ProgressReviewDraft::factory()->create(['apprentice_id'=>$a->id,'author_id'=>$u->id,'fields'=>['reviewNotes'=>'PRIVATE-REVIEW-44']]);
  $this->actingAs($u)->get('/apprentices/'.$a->id.'/work-area')->assertOk()->assertDontSee('PRIVATE-CASE-44')->assertDontSee('PRIVATE-REVIEW-44');
 }
 public function test_context_link_keeps_same_apprentice_and_existing_destination_authorisation():void{
  $u=$this->u('tutor');$a=Apprentice::factory()->create(['tutor_id'=>$u->id]);$this->actingAs($u)->get('/apprentices/'.$a->id)->assertOk()->assertSee(route('quick-actions.person',$a),false);
  $this->get('/apprentices/'.$a->id.'/support')->assertOk();$this->get('/apprentices/'.$a->id.'/record-checks')->assertOk();$this->get('/quick-actions?task=markers')->assertForbidden();
 }
}
