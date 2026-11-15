<?php
namespace Tests\Feature;
use App\Models\{Apprentice,Employer,EmployerApprenticeGrant,SupportCapability,SupportPlan,User,WorkplaceSupportShare as Share};
use App\Support\{EmployerActions,SupportAccess,WorkplaceSupportSharing as Sharing};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\{DB,Notification};
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkplaceSupportSharingTest extends TestCase {
 use LazilyRefreshDatabase;
 private function fixture():array {
  $lead=User::factory()->create(['role'=>'inclusion_lead']);$member=User::factory()->create(['role'=>'inclusion_member']);$learner=User::factory()->create(['role'=>'apprentice']);$employer=User::factory()->create(['role'=>'employer']);$company=Employer::factory()->create();
  $a=Apprentice::factory()->create(['user_id'=>$learner->id,'email'=>$learner->email,'employer_id'=>$company->id]);$manager=User::factory()->create(['role'=>'admin']);
  $g=EmployerActions::grant($manager,1,$a,['user_id'=>$employer->id,'version'=>0,'identity_reference'=>'PRIVATE-EMPLOYER-AUTHORITY','confirmed'=>1]);
  $p=new SupportPlan(['apprentice_id'=>$a->id,'concern_ids'=>[],'draft'=>['PRIVATE-DRAFT-NOTE']]);$p->status='Active';$p->active_plan=['fields'=>['support'=>'PRIVATE-CLINICAL-SUPPORT-TEXT','funding'=>'PRIVATE-FUNDING'],'codes'=>['IM36'],'approved_at'=>now()->toIso8601String(),'approved_by'=>$lead->id,'reason'=>'PRIVATE-APPROVAL-REASON'];$p->save();
  return compact('lead','member','learner','employer','company','a','manager','g','p');
 }
 private function data(array $f):array {return ['request_token'=>(string)Str::uuid(),'plan_id'=>$f['p']->id,'plan_version'=>$f['p']->version,'grant_id'=>$f['g']->id,'grant_version'=>$f['g']->version,'title'=>'Fictional practical support','adjustments'=>'Fictional short written instructions','employer_actions'=>'Fictional weekly check with workplace mentor','expires_on'=>today()->addDays(14)->toDateString()];}
 private function draft(array $f):Share {return Sharing::prepare($f['member'],1,$f['a'],$this->data($f));}
 private function requested(array $f):Share {$s=$this->draft($f);Sharing::requestPermission($f['lead'],1,$s,1);return $s->fresh();}
 private function shared(array $f):Share {$s=$this->requested($f);Sharing::decide($f['learner'],1,$s,2,'allow');return $s->fresh();}
 private function url(Share $s,string $action=''):string {return '/workplace-support/'.$s->id.($action?'/'.$action:'');}
 private function formData(array $f):array {$d=$this->data($f);return [...$d,'plan_choice'=>$f['p']->id.':'.$f['p']->version,'grant_choice'=>$f['g']->id.':'.$f['g']->version,'confirmed'=>1];}
 public function test_member_prepares_minimal_encrypted_private_draft_without_auto_copy_or_messages():void {
  $f=$this->fixture();Notification::fake();$s=$this->draft($f);$raw=DB::table('workplace_support_shares')->where('id',$s->id)->first();$this->assertStringNotContainsString('Fictional short written',$raw->payload);$this->assertStringNotContainsString($f['learner']->email,$raw->source);$this->assertSame('Draft',$s->state);$this->assertNull($s->permission);$this->assertStringNotContainsString('PRIVATE',json_encode($s->payload));$this->assertArrayNotHasKey('payload',$s->toArray());$this->assertDatabaseHas('workplace_support_share_events',['event'=>'draft_prepared']);Notification::assertNothingSent();
 }
 public function test_staff_lead_check_is_required_before_apprentice_sees_or_decides():void {
  $f=$this->fixture();$s=$this->draft($f);$this->actingAs($f['learner'])->get($this->url($s))->assertNotFound();$this->post($this->url($s,'decide'),['version'=>1,'decision'=>'allow','confirmed'=>1])->assertSessionHasErrors('sharing');$this->actingAs($f['member'])->post($this->url($s,'check'),['version'=>1,'confirmed'=>1])->assertForbidden();
  $this->actingAs($f['lead'])->post($this->url($s,'check'),['version'=>1,'confirmed'=>1])->assertSessionHasNoErrors();$this->actingAs($f['learner'])->get($this->url($s))->assertOk()->assertSee($f['employer']->email)->assertSee('Fictional short written instructions')->assertSee('You can say no')->assertDontSee('PRIVATE-CLINICAL')->assertDontSee('PRIVATE-FUNDING')->assertDontSee('IM36');
 }
 public function test_explicit_apprentice_allow_publishes_only_exact_named_employer_summary():void {
  $f=$this->fixture();$s=$this->requested($f);$this->actingAs($f['employer'])->get($this->url($s))->assertNotFound();$this->actingAs($f['learner'])->post($this->url($s,'decide'),['version'=>2,'decision'=>'allow','confirmed'=>1])->assertSessionHasNoErrors();$this->assertTrue(Sharing::employerVisible($f['employer'],$s->fresh()));
  $this->actingAs($f['employer'])->get($this->url($s))->assertOk()->assertHeader('Cache-Control','no-store, private')->assertHeader('Referrer-Policy','no-referrer')->assertSee('Fictional short written instructions')->assertDontSee('PRIVATE')->assertDontSee('IM36')->assertDontSee('/support-plans/',false)->assertDontSee('Restricted staff history');
 }
 public function test_allow_requires_checkbox_and_current_exact_version_but_decline_needs_no_explanation():void {
  $f=$this->fixture();$s=$this->requested($f);$this->actingAs($f['learner']);$this->post($this->url($s,'decide'),['version'=>2,'decision'=>'allow'])->assertSessionHasErrors('confirmed');$this->post($this->url($s,'decide'),['version'=>1,'decision'=>'allow','confirmed'=>1])->assertSessionHasErrors('sharing');$this->post($this->url($s,'decide'),['version'=>2,'decision'=>'decline'])->assertSessionHasNoErrors();$this->assertSame('Declined',$s->fresh()->state);$this->assertFalse(Sharing::employerVisible($f['employer'],$s->fresh()));$this->assertDatabaseHas('workplace_support_share_events',['event'=>'apprentice_declined']);
 }
 public function test_declined_or_withdrawn_summary_cannot_be_reopened_or_reconsented():void {
  $f=$this->fixture();$s=$this->requested($f);Sharing::decide($f['learner'],1,$s,2,'decline');$this->actingAs($f['learner'])->post($this->url($s,'decide'),['version'=>3,'decision'=>'allow','confirmed'=>1])->assertSessionHasErrors('sharing');$this->actingAs($f['lead'])->post($this->url($s,'check'),['version'=>3,'confirmed'=>1])->assertSessionHasErrors('sharing');
  $other=$this->shared($f);Sharing::withdraw($f['learner'],1,$other,3);$this->assertSame('Withdrawn',$other->fresh()->state);$this->actingAs($f['learner'])->post($this->url($other,'decide'),['version'=>4,'decision'=>'allow','confirmed'=>1])->assertSessionHasErrors('sharing');
 }
 public function test_apprentice_can_withdraw_after_employer_receipt_and_future_access_stops():void {
  $f=$this->fixture();$s=$this->shared($f);Sharing::acknowledge($f['employer'],1,$s,3);$this->actingAs($f['learner'])->post($this->url($s,'withdraw'),['version'=>4,'confirmed'=>1])->assertSessionHasNoErrors();$this->actingAs($f['employer'])->get($this->url($s))->assertNotFound();$this->post($this->url($s,'acknowledge'),['version'=>5,'confirmed'=>1])->assertNotFound();$this->assertDatabaseHas('workplace_support_share_events',['event'=>'apprentice_withdrew']);$this->assertNotNull($s->fresh()->acknowledged_at);
 }
 public function test_employer_acknowledgement_is_once_only_and_never_completes_support_training_or_review():void {
  $f=$this->fixture();$s=$this->shared($f);$before=$f['p']->fresh()->getAttributes();$this->actingAs($f['employer'])->post($this->url($s,'acknowledge'),['version'=>3,'confirmed'=>1])->assertSessionHasNoErrors();$this->post($this->url($s,'acknowledge'),['version'=>4,'confirmed'=>1])->assertSessionHasErrors('sharing');$this->assertSame('Shared',$s->fresh()->state);$this->assertSame($before,$f['p']->fresh()->getAttributes());$this->assertDatabaseCount('signed_review_records',0);$this->assertDatabaseHas('workplace_support_share_events',['event'=>'employer_acknowledged']);
 }
 public function test_different_employer_cannot_view_or_ack_even_if_authorised_for_same_apprentice():void {
  $f=$this->fixture();$other=User::factory()->create(['role'=>'employer']);EmployerActions::grant($f['manager'],1,$f['a'],['user_id'=>$other->id,'version'=>0,'identity_reference'=>'FICTIONAL OTHER','confirmed'=>1]);$s=$this->shared($f);$this->actingAs($other)->get($this->url($s))->assertNotFound();$this->post($this->url($s,'acknowledge'),['version'=>3,'confirmed'=>1])->assertNotFound();$this->get('/workplace-support')->assertOk()->assertDontSee($s->id);
 }
 public function test_another_apprentice_cannot_see_decide_or_withdraw_and_staff_cannot_allow():void {
  $f=$this->fixture();$s=$this->requested($f);$other=User::factory()->create(['role'=>'apprentice']);Apprentice::factory()->create(['user_id'=>$other->id]);$this->actingAs($other)->get($this->url($s))->assertNotFound();foreach(['decide','withdraw']as$action){$this->post($this->url($s,$action),['version'=>2,'decision'=>'allow','confirmed'=>1])->assertForbidden();}$this->actingAs($f['lead'])->post($this->url($s,'decide'),['version'=>2,'decision'=>'allow','confirmed'=>1])->assertForbidden();
 }
 public function test_ordinary_tutor_and_unassigned_support_worker_have_no_private_summary_access():void {
  $f=$this->fixture();$s=$this->shared($f);$t=User::factory()->create(['role'=>'tutor']);$f['a']->tutor_id=$t->id;$f['a']->save();$this->actingAs($t)->get($this->url($s))->assertNotFound();$this->get('/workplace-support')->assertForbidden();$this->get('/apprentices/'.$f['a']->id.'/workplace-support')->assertForbidden();
  SupportCapability::create(['user_id'=>$f['member']->id,'duty'=>'member','scope'=>'assigned','apprentice_ids'=>[],'group_ids'=>[],'version'=>1]);$this->actingAs($f['member'])->get($this->url($s))->assertNotFound();$this->get('/apprentices/'.$f['a']->id.'/workplace-support')->assertForbidden();
 }
 public function test_ordinary_manager_oversight_does_not_grant_writer_or_lead_permissions():void {
  $f=$this->fixture();$s=$this->draft($f);$this->actingAs($f['manager'])->get($this->url($s))->assertOk();$this->post('/apprentices/'.$f['a']->id.'/workplace-support',$this->formData($f))->assertForbidden();$this->post($this->url($s,'check'),['version'=>1,'confirmed'=>1])->assertForbidden();$this->post($this->url($s,'withdraw'),['version'=>1,'confirmed'=>1])->assertForbidden();
 }
 public function test_active_approved_plan_and_confirmed_unique_linked_apprentice_are_required():void {
  $f=$this->fixture();$this->actingAs($f['member']);$url='/apprentices/'.$f['a']->id.'/workplace-support';
  foreach([['status','Closed'],['active_plan',null]]as[$key,$value]){$old=$f['p']->$key;$f['p']->$key=$value;$f['p']->save();$this->post($url,$this->formData($f))->assertSessionHasErrors('sharing');$f['p']->$key=$old;$f['p']->save();}
  $f['learner']->email_verified_at=null;$f['learner']->save();$this->post($url,$this->formData($f))->assertSessionHasErrors('sharing');$this->assertDatabaseCount('workplace_support_shares',0);
 }
 public function test_stale_plan_or_grant_versions_and_foreign_source_are_refused():void {
  $f=$this->fixture();$this->actingAs($f['member']);$url='/apprentices/'.$f['a']->id.'/workplace-support';$d=$this->formData($f);$d['plan_choice']=$f['p']->id.':9';$this->post($url,$d)->assertSessionHasErrors('sharing');$d=$this->formData($f);$d['grant_choice']=$f['g']->id.':9';$this->post($url,$d)->assertSessionHasErrors('sharing');$other=$this->fixture();$d=$this->formData($f);$d['plan_choice']=$other['p']->id.':1';$this->post($url,$d)->assertNotFound();$this->assertDatabaseCount('workplace_support_shares',0);
 }
 public function test_same_request_is_idempotent_but_changed_payload_cannot_reuse_it():void {
  $f=$this->fixture();$d=$this->formData($f);$this->actingAs($f['member'])->post('/apprentices/'.$f['a']->id.'/workplace-support',$d)->assertSessionHasNoErrors();$this->post('/apprentices/'.$f['a']->id.'/workplace-support',$d)->assertSessionHasNoErrors();$this->assertDatabaseCount('workplace_support_shares',1);$this->assertDatabaseCount('workplace_support_share_events',1);$d['title']='Changed';$this->post('/apprentices/'.$f['a']->id.'/workplace-support',$d)->assertSessionHasErrors('sharing');
 }
 public function test_form_requires_checked_minimal_fields_uuid_and_bounded_expiry():void {
  $f=$this->fixture();$this->actingAs($f['member']);$url='/apprentices/'.$f['a']->id.'/workplace-support';foreach(['confirmed','title','adjustments','employer_actions','expires_on','request_token']as$field){$d=$this->formData($f);unset($d[$field]);$this->post($url,$d)->assertSessionHasErrors($field);}
  foreach([today()->subDay(),today()->addDays(31)]as$day){$d=$this->formData($f);$d['expires_on']=$day->toDateString();$this->post($url,$d)->assertSessionHasErrors('expires_on');}$this->assertDatabaseCount('workplace_support_shares',0);
 }
 public function test_expiry_stops_employer_access_and_ack_but_apprentice_can_still_withdraw():void {
  $f=$this->fixture();$s=$this->shared($f);$this->travelTo($s->expires_at);$this->assertFalse(Sharing::employerVisible($f['employer'],$s));$this->actingAs($f['employer'])->get($this->url($s))->assertNotFound();$this->post($this->url($s,'acknowledge'),['version'=>3,'confirmed'=>1])->assertNotFound();$this->actingAs($f['learner'])->post($this->url($s,'withdraw'),['version'=>3,'confirmed'=>1])->assertSessionHasNoErrors();
 }
 public function test_plan_reapproval_or_closure_stops_existing_shared_access_but_draft_change_does_not():void {
  $f=$this->fixture();$s=$this->shared($f);$f['p']->draft=['CHANGED PRIVATE DRAFT'];$f['p']->version++;$f['p']->save();$this->assertTrue(Sharing::employerVisible($f['employer'],$s));$active=$f['p']->active_plan;$active['fields']['support']='NEW APPROVED';$f['p']->active_plan=$active;$f['p']->save();$this->assertFalse(Sharing::employerVisible($f['employer'],$s));$f['p']->active_plan=['fields'=>['support'=>'PRIVATE-CLINICAL-SUPPORT-TEXT','funding'=>'PRIVATE-FUNDING'],'codes'=>['IM36'],'approved_at'=>$active['approved_at'],'approved_by'=>$f['lead']->id,'reason'=>'PRIVATE-APPROVAL-REASON'];$f['p']->status='Closed';$f['p']->save();$this->assertFalse(Sharing::employerVisible($f['employer'],$s));
 }
 public function test_employer_revoke_regrant_profile_change_and_recipient_account_change_hold_access():void {
  $f=$this->fixture();$s=$this->shared($f);$f['g']->active=false;$f['g']->save();$this->assertFalse(Sharing::employerVisible($f['employer'],$s));$f['g']->active=true;$f['g']->version++;$f['g']->save();$this->assertFalse(Sharing::employerVisible($f['employer'],$s));
  $f=$this->fixture();$s=$this->shared($f);$f['a']->employer_id=null;$f['a']->save();$this->assertFalse(Sharing::employerVisible($f['employer'],$s));
  $f=$this->fixture();$s=$this->shared($f);$f['employer']->auth_version++;$f['employer']->save();$this->assertFalse(Sharing::employerVisible($f['employer'],$s));
 }
 public function test_apprentice_relink_identity_or_account_access_change_holds_access():void {
  foreach(['user_id','first_name','reference']as$field){$f=$this->fixture();$s=$this->shared($f);$f['a']->$field=$field==='user_id'?null:'CHANGED';$f['a']->save();$this->assertFalse(Sharing::employerVisible($f['employer'],$s));}
  $f=$this->fixture();$s=$this->shared($f);$f['learner']->auth_version++;$f['learner']->save();$this->assertFalse(Sharing::employerVisible($f['employer'],$s));
 }
 public function test_stale_source_cannot_be_checked_or_allowed_but_can_be_declined_or_withdrawn():void {
  $f=$this->fixture();$s=$this->requested($f);$f['g']->active=false;$f['g']->save();$this->actingAs($f['learner'])->post($this->url($s,'decide'),['version'=>2,'decision'=>'allow','confirmed'=>1])->assertSessionHasErrors('sharing');$this->post($this->url($s,'decide'),['version'=>2,'decision'=>'decline'])->assertSessionHasNoErrors();$this->assertSame('Declined',$s->fresh()->state);
 }
 public function test_pending_and_declined_records_never_appear_in_employer_queue_or_count():void {
  $f=$this->fixture();$draft=$this->draft($f);$pending=$this->requested($f);$declined=$this->requested($f);Sharing::decide($f['learner'],1,$declined,2,'decline');$this->actingAs($f['employer'])->get('/workplace-support')->assertOk()->assertDontSee($draft->id)->assertDontSee($pending->id)->assertDontSee($declined->id)->assertDontSee('Awaiting apprentice');$this->actingAs($f['learner'])->get('/workplace-support')->assertOk()->assertSee($pending->id)->assertDontSee($draft->id);
 }
 public function test_read_only_pages_cannot_grant_permission_or_acknowledge_and_escape_summary_html():void {
  $f=$this->fixture();$d=$this->data($f);$d['adjustments']='<script>FICTIONAL-XSS</script>';$s=Sharing::prepare($f['member'],1,$f['a'],$d);Sharing::requestPermission($f['lead'],1,$s,1);$this->actingAs($f['learner'])->get($this->url($s))->assertOk()->assertSee('&lt;script&gt;FICTIONAL-XSS&lt;/script&gt;',false)->assertDontSee('<script>FICTIONAL-XSS</script>',false);$this->get($this->url($s))->assertOk();$this->assertSame('Awaiting apprentice',$s->fresh()->state);$this->assertDatabaseCount('workplace_support_share_events',2);
 }
 public function test_stale_decision_withdraw_and_ack_forms_cannot_overwrite_history():void {
  $f=$this->fixture();$s=$this->shared($f);$this->actingAs($f['learner'])->post($this->url($s,'withdraw'),['version'=>2,'confirmed'=>1])->assertSessionHasErrors('sharing');$this->actingAs($f['employer'])->post($this->url($s,'acknowledge'),['version'=>2,'confirmed'=>1])->assertSessionHasErrors('sharing');$this->assertSame(3,$s->fresh()->version);$this->assertDatabaseCount('workplace_support_share_events',3);
 }
 public function test_staff_withdraws_draft_or_live_share_but_employer_cannot_withdraw():void {
  $f=$this->fixture();$s=$this->draft($f);Sharing::withdraw($f['member'],1,$s,1);$this->assertSame('Withdrawn',$s->fresh()->state);$s=$this->shared($f);$this->actingAs($f['employer'])->post($this->url($s,'withdraw'),['version'=>3,'confirmed'=>1])->assertForbidden();$this->actingAs($f['member'])->post($this->url($s,'withdraw'),['version'=>3,'confirmed'=>1])->assertSessionHasNoErrors();$this->assertFalse(Sharing::employerVisible($f['employer'],$s->fresh()));
 }
 public function test_corrupt_payload_source_permission_and_expiry_cannot_expose_content():void {
  $f=$this->fixture();$s=$this->shared($f);$original=$s->getAttributes();foreach(['payload','source','permission']as$field){DB::table('workplace_support_shares')->where('id',$s->id)->update([$field=>'CORRUPT PRIVATE VALUE']);$this->assertFalse(Sharing::employerVisible($f['employer'],$s->fresh()));$this->actingAs($f['employer'])->get($this->url($s))->assertNotFound();DB::table('workplace_support_shares')->where('id',$s->id)->update([$field=>$original[$field]]);}
  DB::table('workplace_support_shares')->where('id',$s->id)->update(['expires_at'=>today()->addDays(29)]);$this->assertFalse(Sharing::employerVisible($f['employer'],$s->fresh()));
 }
 public function test_forged_shared_state_without_encrypted_exact_permission_is_not_access():void {
  $f=$this->fixture();$s=$this->requested($f);DB::table('workplace_support_shares')->where('id',$s->id)->update(['state'=>'Shared','decided_at'=>now()]);$this->assertFalse(Sharing::employerVisible($f['employer'],$s->fresh()));$this->actingAs($f['employer'])->get($this->url($s))->assertNotFound();
 }
 public function test_stale_actor_access_and_removed_lead_duty_cannot_publish():void {
  $f=$this->fixture();$s=$this->draft($f);$f['lead']->auth_version=2;$f['lead']->save();try{Sharing::requestPermission($f['lead'],1,$s,1);$this->fail('stale accepted');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(403,$e->getStatusCode());}SupportCapability::create(['user_id'=>$f['lead']->id,'duty'=>'staff','scope'=>'all','version'=>1]);$this->actingAs($f['lead']->fresh())->post($this->url($s,'check'),['version'=>1,'confirmed'=>1])->assertForbidden();$this->assertSame('Draft',$s->fresh()->state);
 }
 public function test_maximum_multibyte_text_round_trips_with_large_encrypted_storage():void {
  $f=$this->fixture();$d=$this->data($f);$d['adjustments']=str_repeat('🚙',4000);$d['employer_actions']=str_repeat('🚚',4000);$s=Sharing::prepare($f['member'],1,$f['a'],$d);$this->assertSame($d['adjustments'],$s->fresh()->payload['adjustments']);$raw=DB::table('workplace_support_shares')->where('id',$s->id)->value('payload');$this->assertGreaterThan(65535,strlen($raw));$this->assertTrue(Sharing::current($s->fresh()));
 }
 public function test_changed_check_metadata_or_payload_hash_cannot_reuse_apprentice_permission():void {
  $f=$this->fixture();$s=$this->shared($f);DB::table('workplace_support_shares')->where('id',$s->id)->update(['checked_by'=>$f['manager']->id]);$this->assertFalse(Sharing::employerVisible($f['employer'],$s->fresh()));DB::table('workplace_support_shares')->where('id',$s->id)->update(['checked_by'=>$f['lead']->id,'payload_hash'=>str_repeat('0',64)]);$this->assertFalse(Sharing::employerVisible($f['employer'],$s->fresh()));
 }
 public function test_all_mutations_are_post_only_and_new_page_links_exist_for_correct_roles():void {
  $f=$this->fixture();$s=$this->requested($f);$this->actingAs($f['learner']);foreach(['decide','withdraw','check','acknowledge']as$action){$this->get($this->url($s,$action))->assertStatus(405);}$this->get('/my-next-step')->assertSee('Review workplace support sharing choices');$this->actingAs($f['employer'])->get('/employer-actions')->assertSee('Workplace support summaries shared with me');$this->actingAs($f['lead'])->get('/apprentices/'.$f['a']->id.'/support-plans/'.$f['p']->id)->assertOk()->assertSee('Workplace support sharing');
 }
}
