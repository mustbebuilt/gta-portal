<?php
namespace Tests\Feature;
use App\Models\{User,Apprentice,ProgressReviewDraft,SupportPlan,IndividualDeliveryPlan,SupportCapability};
use App\Support\SavedPortalWork as W;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class SavedPortalWorkTest extends TestCase
{
 use LazilyRefreshDatabase;
 private function f():array{$u=User::factory()->create(['role'=>'tutor']);$a=Apprentice::factory()->create(['tutor_id'=>$u->id]);return[$u,$a];}
 private function review(Apprentice $a,User $u,string $state='Draft'):ProgressReviewDraft{$p=ProgressReviewDraft::factory()->create(['apprentice_id'=>$a->id,'author_id'=>$u->id,'fields'=>['note'=>'PRIVATE-UNFINISHED-44']]);$p->forceFill(['state'=>$state])->save();return$p;}
 public function test_current_assignment_scopes_reviews_and_removes_former_owner_access():void{
  [$u,$a]=$this->f();$p=$this->review($a,$u);$b=Apprentice::factory()->create();$this->review($b,$u);
  $rows=W::read($u);$this->assertSame([$p->id],$rows['items']->pluck('id')->all());$a->update(['tutor_id'=>null]);$this->assertCount(0,W::read($u)['items']);
 }
 public function test_sources_are_metadata_only_and_private_bodies_are_not_selected_or_rendered():void{
  [$u,$a]=$this->f();$this->review($a,$u);$queries=[];DB::listen(function($event)use(&$queries){$queries[]=$event->sql;});
  $this->actingAs($u)->get('/saved-work')->assertOk()->assertDontSee('PRIVATE-UNFINISHED-44')->assertSee('Continue at source')->assertHeader('Referrer-Policy','no-referrer');
  $selects=array_filter($queries,fn($q)=>str_starts_with($q,'select')&&str_contains($q,'progress_review_drafts'));$this->assertNotEmpty($selects);
  foreach($selects as$q){$this->assertStringNotContainsString('fields',$q);$this->assertStringNotContainsString('source_snapshot',$q);}
 }
 public function test_ready_or_submitted_review_is_not_presented_as_an_editable_draft():void{
  [$u,$a]=$this->f();$this->review($a,$u,'Ready for signatures');$this->assertCount(0,W::read($u)['items']);
 }
 public function test_support_drafts_require_current_team_scope_and_do_not_grant_training_access():void{
  [$other,$a]=$this->f();$u=User::factory()->create(['role'=>'staff']);$s=SupportPlan::create(['apprentice_id'=>$a->id,'concern_ids'=>[],'draft'=>['private'=>'PRIVATE-SUPPORT-DRAFT']]);
  SupportCapability::create(['user_id'=>$u->id,'duty'=>'member','scope'=>'assigned','apprentice_ids'=>[$a->id],'group_ids'=>[]]);
  $this->actingAs($u)->get('/saved-work')->assertOk()->assertSee(route('support.plan',[$a,$s]),false)->assertDontSee('PRIVATE-SUPPORT-DRAFT');
  $this->get('/apprentices/'.$a->id.'/record-checks')->assertForbidden();SupportCapability::where('user_id',$u->id)->update(['duty'=>'staff']);$this->get('/saved-work')->assertForbidden();
 }
 public function test_active_approved_delivery_plans_are_excluded_and_inactive_drafts_link_to_original():void{
  [$u,$a]=$this->f();$p=IndividualDeliveryPlan::create(['apprentice_id'=>$a->id,'history'=>[],'draft'=>['private'=>'PRIVATE-DELIVERY-DRAFT']]);
  $this->assertSame(route('delivery.show',$a),W::read($u)['items']->first()['url']);$p->forceFill(['active'=>true])->save();$this->assertCount(0,W::read($u)['items']);
 }
 public function test_staff_home_resumes_an_existing_setup_in_its_original_wizard(): void
 {
  $admin = User::factory()->create(['role' => 'admin']);
  $draft = \App\Models\ApprenticeSetupDraft68::create([
   'id' => (string) \Illuminate\Support\Str::uuid(), 'owner_id' => $admin->id, 'history' => [],
   'payload' => ['details' => ['first_name' => 'Fictional', 'last_name' => 'Setupperson', 'email' => 'private@example.invalid']],
  ]);
  $this->actingAs($admin)->get(route('attention.index'))->assertOk()
   ->assertSee('Continue a draft')->assertSee(route('saved-work.index'), false);
  $this->get('/saved-work?kind=setup&search=Setupperson')->assertOk()->assertSee('Fictional Setupperson')
   ->assertSee(route('setup68.show', $draft), false)->assertDontSee('private@example.invalid');
  $this->get(route('setup68.show', $draft))->assertOk()->assertSee('Setupperson');
  $this->actingAs(User::factory()->create(['role' => 'tutor']))->get('/saved-work?kind=setup')
   ->assertOk()->assertDontSee('Setupperson');
 }

 public function test_admin_can_resume_a_changed_approved_plan_but_the_unchanged_copy_is_hidden(): void
 {
  [$tutor, $apprentice] = $this->f();
  $admin = User::factory()->create(['role' => 'admin']);
  $draft = ['details' => ['source_ref' => 'Original plan']];
  $plan = IndividualDeliveryPlan::create(['apprentice_id' => $apprentice->id, 'draft' => $draft, 'history' => []]);
  $plan->forceFill(['active' => true, 'approved' => ['draft_hash' => \App\Support\DeliveryPlan::draftHash($draft)]])->save();
  $this->assertCount(0, W::read($admin, 'delivery')['items']);
  $plan->forceFill(['draft' => ['details' => ['source_ref' => 'Fictional revision']]])->save();
  $this->assertSame([$plan->id], W::read($admin, 'delivery')['items']->pluck('id')->all());
  $this->assertCount(0, W::read($tutor, 'delivery')['items']);
 }

 public function test_empty_saved_delivery_plan_still_needs_source_review():void{
  [$u,$a]=$this->f();IndividualDeliveryPlan::create(['apprentice_id'=>$a->id,'draft'=>[],'history'=>[]]);$this->assertCount(1,W::read($u)['items']);
 }
 public function test_kind_and_name_search_are_applied_before_source_counts_and_limit():void{
  [$u,$a]=$this->f();$p=$this->review($a,$u);IndividualDeliveryPlan::create(['apprentice_id'=>$a->id,'history'=>[],'draft'=>['blocks'=>[]]]);
  $rows=W::read($u,'review',$a->reference);$this->assertCount(1,$rows['items']);$this->assertSame(1,$rows['coverage']['review']['matched']);$this->assertArrayNotHasKey('delivery',$rows['coverage']);
 }
 public function test_source_limit_is_disclosed_and_narrow_search_finds_older_records():void{
  [$u,$a]=$this->f();$older=Apprentice::factory()->create(['tutor_id'=>$u->id,'reference'=>'OLDER-DRAFT-44']);$this->review($older,$u);
  ProgressReviewDraft::factory()->count(201)->create(['apprentice_id'=>$a->id,'author_id'=>$u->id]);$rows=W::read($u,'review');$this->assertTrue($rows['limited']);$this->assertSame(202,$rows['coverage']['review']['matched']);$this->assertCount(200,$rows['items']);
  $this->assertCount(1,W::read($u,'review','OLDER-DRAFT-44')['items']);$this->actingAs($u)->get('/saved-work?kind=review')->assertOk()->assertSee('Narrow the search to find older work');
 }
 public function test_equal_timestamp_order_is_stable_and_page_links_keep_filters():void{
  [$u,$a]=$this->f();ProgressReviewDraft::factory()->count(23)->create(['apprentice_id'=>$a->id,'author_id'=>$u->id]);$one=W::read($u)['items']->pluck('id')->all();$this->assertSame($one,W::read($u)['items']->pluck('id')->all());
  $this->actingAs($u)->get('/saved-work?kind=review&page=2')->assertOk()->assertViewHas('items',fn($p)=>$p->total()===23&&count($p->items())===3)->assertSee('kind=review',false);
 }
 #[\PHPUnit\Framework\Attributes\TestWith(['apprentice'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['employer'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['careers_adviser'])]
 public function test_unrelated_roles_do_not_gain_private_draft_workspaces(string $role):void{
  $this->actingAs(User::factory()->create(['role'=>$role]))->get('/saved-work')->assertForbidden();
 }
 #[\PHPUnit\Framework\Attributes\TestWith(['kind[]=review'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['kind=not-real'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['page=0'])]
 public function test_invalid_filters_cannot_expand_scope(string $query):void{[$u]=$this->f();$this->actingAs($u)->getJson('/saved-work?'.$query)->assertUnprocessable();}
}
