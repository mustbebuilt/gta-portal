<?php
namespace Tests\Feature;
use App\Models\{Apprentice,ApprenticeExperienceEntry,LearningTask,LearningBlockControl,SmartAction,TrainingSession,User,SupportCapability};
use App\Support\{ApprenticeExperience,JourneyEvents,StaffDuties};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Schema,Storage};
use Illuminate\Support\Str;
use Tests\TestCase;
class ApprenticeExperienceTest extends TestCase {
 use LazilyRefreshDatabase;
 private function fixture():array {
  $u=User::factory()->create(['role'=>'apprentice']);$t=User::factory()->create(['role'=>'tutor']);$a=Apprentice::factory()->create(['user_id'=>$u->id,'tutor_id'=>$t->id]);return [$u,$t,$a];
 }
 private function data(string $kind='help_task'):array {return ['request_token'=>(string)Str::uuid(),'kind'=>$kind,'title'=>'Fictional title','detail'=>'FICTIONAL REQUEST TEXT','happened_on'=>$kind==='achievement'?today()->toDateString():null];}
 private function create(User $u,string $kind='help_task'):ApprenticeExperienceEntry {return ApprenticeExperience::create($u,$u->auth_version,$this->data($kind));}
 private function response(ApprenticeExperienceEntry $e,string $state='Received'):array {return ['version'=>$e->version,'state'=>$state,'response'=>'FICTIONAL STAFF RESPONSE'];}
 public function test_home_uses_only_own_available_tasks_and_no_private_planning():void {
  [$u,$t,$a]=$this->fixture();$task=LearningTask::factory()->create(['apprentice_id'=>$a->id,'due_date'=>today()->subDay(),'title'=>'OWN AVAILABLE TASK']);$task->deadline_history=[['reason'=>'PRIVATE-PLANNING']];$task->save();LearningTask::factory()->create(['title'=>'OTHER LEARNER SECRET']);
  $this->actingAs($u)->get('/my-next-step')->assertOk()->assertSee('OWN AVAILABLE TASK')->assertSee(route('tasks.show',$task),false)->assertDontSee('OTHER LEARNER SECRET')->assertDontSee('PRIVATE-PLANNING')->assertSee('Your progress journey')->assertSee('Gateway preparation');
 }
 public function test_profile_missing_or_duplicate_blocks_home_and_mutation():void {
  $u=User::factory()->create(['role'=>'apprentice']);$this->actingAs($u)->get('/my-next-step')->assertStatus(409)->assertSee('Your profile needs linking');$this->post('/my-help-and-achievements',$this->data())->assertStatus(409);
  Apprentice::factory()->create(['user_id'=>$u->id]);DB::statement('DROP INDEX apprentices_user_id_unique');Apprentice::factory()->create(['user_id'=>$u->id]);$this->get('/my-next-step')->assertStatus(409);$this->post('/my-help-and-achievements',$this->data())->assertStatus(409);$this->assertDatabaseCount('apprentice_experience_entries',0);
 }
 public function test_other_roles_cannot_use_learner_home_or_create_entries():void {
  foreach(['admin','ceo','tutor','employer','staff','mentor']as$role){$u=User::factory()->create(['role'=>$role]);$this->actingAs($u)->get('/my-next-step')->assertForbidden();$this->post('/my-help-and-achievements',$this->data())->assertForbidden();}
 }
 public function test_next_task_excludes_paper_awaiting_locked_and_source_pending():void {
  [$u,$t,$a]=$this->fixture();$b1=LearningTask::factory()->create(['apprentice_id'=>$a->id,'status'=>'Awaiting marking']);$b2=LearningTask::factory()->create(['apprentice_id'=>$a->id,'reference'=>'b2-t3','block'=>2]);LearningBlockControl::create(['apprentice_id'=>$a->id,'enabled'=>true,'exceptions'=>[],'history'=>[]]);
  $this->assertNull(ApprenticeExperience::home($a)['next']);$b1->status='Draft';$b1->template=['kind'=>'source-pending'];$b1->save();$this->assertNull(ApprenticeExperience::home($a)['next']);$b1->template=['kind'=>'knowledge'];$b1->save();$this->assertNull(ApprenticeExperience::home($a)['next']);
  $b1->template=\App\Support\TaskCatalogue::template('b1-t3');$b1->status='Referred';$b1->save();$this->assertSame('Make your task changes',ApprenticeExperience::home($a)['next']['label']);
 }
 public function test_overdue_own_action_takes_priority_and_other_owners_do_not():void {
  [$u,$t,$a]=$this->fixture();LearningTask::factory()->create(['apprentice_id'=>$a->id,'due_date'=>today()->addDay()]);$x=SmartAction::factory()->create(['apprentice_id'=>$a->id,'owner'=>'Apprentice','due_date'=>today()->subDay()]);SmartAction::factory()->create(['apprentice_id'=>$a->id,'owner'=>'Tutor','title'=>'PRIVATE STAFF ACTION']);
  $p=ApprenticeExperience::home($a);$this->assertSame(route('actions.index',$a).'#action-'.$x->id,$p['next']['url']);$this->assertCount(1,$p['actions']);$this->actingAs($u)->get('/my-next-step')->assertDontSee('PRIVATE STAFF ACTION');
 }
 public function test_session_uses_saved_members_future_time_and_cancelled_filter():void {
  [$u,$t,$a]=$this->fixture();TrainingSession::factory()->create(['session_date'=>today()->addDay(),'state'=>'Cancelled','members'=>[$a->id],'name'=>'CANCELLED SECRET']);TrainingSession::factory()->create(['session_date'=>today()->addDay(),'members'=>[],'name'=>'OTHER ROSTER SECRET']);$s=TrainingSession::factory()->create(['session_date'=>today()->addDays(2),'members'=>[$a->id],'name'=>'YOUR NEXT SESSION']);
  $this->assertSame($s->id,ApprenticeExperience::home($a)['session']->id);$this->actingAs($u)->get('/my-next-step')->assertSee('YOUR NEXT SESSION')->assertDontSee('CANCELLED SECRET')->assertDontSee('OTHER ROSTER SECRET')->assertSee('needs confirming with your tutor');
 }
 public function test_empty_and_partial_blocks_never_claim_completion():void {
  [$u,$t,$a]=$this->fixture();$b=ApprenticeExperience::blocks($a);$this->assertCount(12,$b);$this->assertSame('Tasks not allocated',$b[0]['status']);LearningTask::factory()->create(['apprentice_id'=>$a->id,'status'=>'Approved']);$b=ApprenticeExperience::blocks($a);$this->assertSame(1,$b[0]['approved']);$this->assertFalse($b[0]['complete']);$this->assertFalse($b[10]['complete']);$this->assertNull($b[11]['approved']);
 }
 public function test_five_source_tasks_show_block_approval_without_gateway_completion():void {
  [$u,$t,$a]=$this->fixture();foreach(\App\Support\TaskCatalogue::tasks('autocare')as$item){if((int)$item['block']===1){LearningTask::factory()->create(['apprentice_id'=>$a->id,'reference'=>$item['id'],'template'=>\App\Support\TaskCatalogue::template($item['id']),'status'=>'Approved']);}}
  $rows=ApprenticeExperience::blocks($a);$this->assertTrue($rows[0]['complete']);$this->assertFalse($rows[10]['complete']);$this->actingAs($u)->get(route('journey.show',$a))->assertOk()->assertSee('Personal achievement timeline')->assertSee('Separate checks and sign-off required');
 }
 public function test_help_receipt_idempotency_and_server_owned_fields():void {
  [$u,$t,$a]=$this->fixture();$data=$this->data()+['apprentice_id'=>999,'requester_id'=>$t->id,'state'=>'Closed','response'=>'FORGED'];$this->actingAs($u)->post('/my-help-and-achievements',$data)->assertRedirect()->assertSessionHasNoErrors();$this->post('/my-help-and-achievements',$data)->assertRedirect();$this->assertDatabaseCount('apprentice_experience_entries',1);$e=ApprenticeExperienceEntry::first();$this->assertSame($a->id,$e->apprentice_id);$this->assertSame($u->id,$e->requester_id);$this->assertSame('New',$e->state);$this->assertNull($e->response);$this->assertCount(1,$e->history);$this->get('/my-help-and-achievements?filter=all')->assertSee('GTA-'.$e->id)->assertSee('Waiting for a staff response');
 }
 public function test_token_collision_from_another_profile_is_refused():void {
  [$u,$t,$a]=$this->fixture();$e=$this->create($u);[$other]=$this->fixture();$data=$this->data();$data['request_token']=$e->request_token;$this->actingAs($other)->post('/my-help-and-achievements',$data)->assertStatus(409);$this->assertDatabaseCount('apprentice_experience_entries',1);
 }
 public function test_validation_rejects_unknown_kind_future_achievement_and_missing_detail():void {
  [$u]=$this->fixture();$this->actingAs($u);foreach([['kind'=>'arbitrary'],['detail'=>''],['request_token'=>'bad'],['title'=>str_repeat('x',161)]]as$changes){$this->post('/my-help-and-achievements',array_replace($this->data(),$changes))->assertSessionHasErrors();}
  $this->post('/my-help-and-achievements',array_replace($this->data('achievement'),['happened_on'=>today()->addDay()->toDateString()]))->assertSessionHasErrors('happened_on');$this->post('/my-help-and-achievements',array_replace($this->data('achievement'),['happened_on'=>null]))->assertSessionHasErrors('happened_on');$this->assertDatabaseCount('apprentice_experience_entries',0);
 }
 public function test_unrelated_users_cannot_read_or_respond_and_receipts_do_not_leak():void {
  [$u,$t,$a]=$this->fixture();$e=$this->create($u);foreach(['tutor','employer','functional_skills_tutor','staff','apprentice']as$role){$other=User::factory()->create(['role'=>$role]);if($role==='apprentice'){Apprentice::factory()->create(['user_id'=>$other->id]);}$this->actingAs($other)->post(route('experience.respond',$e),$this->response($e))->assertForbidden();$response=$this->get('/my-help-and-achievements?filter=all');$this->assertStringNotContainsString('FICTIONAL REQUEST TEXT',$response->getContent());}
  $this->assertSame('New',$e->fresh()->state);
 }
 public function test_private_request_is_only_visible_to_scoped_inclusion_duty():void {
  [$u,$t,$a]=$this->fixture();$e=$this->create($u,'help_private');$admin=User::factory()->create(['role'=>'admin']);$mentor=User::factory()->create(['role'=>'mentor']);$a->mentor_id=$mentor->id;$a->save();
  foreach([$t,$admin,$mentor]as$x){$this->actingAs($x)->get('/my-help-and-achievements?filter=all')->assertOk()->assertDontSee('FICTIONAL REQUEST TEXT');$this->post(route('experience.respond',$e),$this->response($e))->assertForbidden();}
  $in=User::factory()->create(['role'=>'inclusion_member']);$this->actingAs($in)->get('/my-help-and-achievements?filter=all')->assertOk()->assertSee('FICTIONAL REQUEST TEXT');$this->post(route('experience.respond',$e),$this->response($e))->assertRedirect()->assertSessionHasNoErrors();$this->assertSame('Received',$e->fresh()->state);
 }
 public function test_private_scope_revocation_takes_effect_before_response():void {
  [$u,$t,$a]=$this->fixture();$e=$this->create($u,'help_private');$in=User::factory()->create(['role'=>'inclusion_member']);$cap=SupportCapability::create(['user_id'=>$in->id,'duty'=>'member','scope'=>'assigned','apprentice_ids'=>[$a->id],'group_ids'=>[]]);$this->actingAs($in)->get('/my-help-and-achievements')->assertSee('FICTIONAL REQUEST TEXT');$cap->apprentice_ids=[];$cap->save();$this->get('/my-help-and-achievements?filter=all')->assertDontSee('FICTIONAL REQUEST TEXT');$this->post(route('experience.respond',$e),$this->response($e))->assertForbidden();
 }
 public function test_employer_issue_routes_to_current_mentor_and_falls_back_to_main_tutor():void {
  [$u,$t,$a]=$this->fixture();$e=$this->create($u,'help_employer');$m=User::factory()->create(['role'=>'mentor']);$a->mentor_id=$m->id;$a->save();$this->assertTrue(ApprenticeExperience::canHandle($m,$e));$this->assertFalse(ApprenticeExperience::canHandle($t,$e));$this->assertFalse(ApprenticeExperience::queue($t)->whereKey($e->id)->exists());$m->active=false;$m->save();$this->assertTrue(ApprenticeExperience::canHandle($t,$e));$this->assertFalse(ApprenticeExperience::canHandle($m,$e));$this->assertTrue(ApprenticeExperience::queue($t)->whereKey($e->id)->exists());
 }
 public function test_successor_learner_cannot_read_predecessor_private_receipts():void {
  [$u,$t,$a]=$this->fixture();$e=$this->create($u,'help_private');$next=User::factory()->create(['role'=>'apprentice']);$a->user_id=$next->id;$a->save();$this->actingAs($next)->get('/my-help-and-achievements?filter=all')->assertOk()->assertDontSee('FICTIONAL REQUEST TEXT');$this->actingAs($u)->get('/my-help-and-achievements?filter=all')->assertStatus(409);
 }
 public function test_help_response_receipt_close_history_and_stale_form():void {
  [$u,$t]=$this->fixture();$e=$this->create($u);$this->actingAs($t)->post(route('experience.respond',$e),$this->response($e))->assertRedirect()->assertSessionHasNoErrors();$this->post(route('experience.respond',$e),$this->response($e,'Closed'))->assertSessionHasErrors('version');$e->refresh();$this->post(route('experience.respond',$e),$this->response($e,'Closed'))->assertRedirect();$e->refresh();$this->assertSame(3,$e->version);$this->assertCount(3,$e->history);$this->post(route('experience.respond',$e),$this->response($e))->assertSessionHasErrors('state');$this->actingAs($u)->get('/my-help-and-achievements?filter=all')->assertSee('Closed')->assertSee('FICTIONAL STAFF RESPONSE');
 }
 public function test_response_requires_text_and_cannot_approve_help():void {
  [$u,$t]=$this->fixture();$e=$this->create($u);$this->actingAs($t)->post(route('experience.respond',$e),array_replace($this->response($e),['response'=>'']))->assertSessionHasErrors('response');$this->post(route('experience.respond',$e),$this->response($e,'Approved'))->assertSessionHasErrors('state');$this->assertSame('New',$e->fresh()->state);
 }
 public function test_achievement_is_pending_until_checked_and_withdrawal_removes_timeline_event():void {
  [$u,$t,$a]=$this->fixture();$e=$this->create($u,'achievement');$this->assertSame([],JourneyEvents::for($a));$this->actingAs($u)->post(route('experience.respond',$e),$this->response($e,'Approved'))->assertForbidden();$this->actingAs($t)->post(route('experience.respond',$e),$this->response($e,'Approved'))->assertRedirect()->assertSessionHasNoErrors();$events=JourneyEvents::for($a);$this->assertCount(1,$events);$this->assertSame('Checked workplace achievement',$events[0]['type']);$this->assertSame(0,LearningTask::where('status','Approved')->count());$e->refresh();$this->post(route('experience.respond',$e),$this->response($e,'Referred'))->assertRedirect();$this->assertSame([],JourneyEvents::for($a));$this->assertCount(3,$e->fresh()->history);
 }
 public function test_private_requests_never_appear_on_public_training_journey():void {
  [$u,$t,$a]=$this->fixture();$this->create($u,'help_private');$this->actingAs($t)->get(route('journey.show',$a))->assertOk()->assertDontSee('FICTIONAL REQUEST TEXT');$this->assertSame([],JourneyEvents::for($a));
 }
 public function test_entry_and_response_escape_html():void {
  [$u,$t]=$this->fixture();$data=$this->data();$data['detail']='<script>UNTRUSTED</script>';$e=ApprenticeExperience::create($u,$u->auth_version,$data);$this->actingAs($u)->get('/my-help-and-achievements?filter=all')->assertSee('&lt;script&gt;UNTRUSTED&lt;/script&gt;',false)->assertDontSee('<script>UNTRUSTED</script>',false);
 }
 public function test_reassignment_and_actor_auth_version_are_rechecked_by_write_service():void {
  [$u,$t,$a]=$this->fixture();$e=$this->create($u);$a->tutor_id=null;$a->save();try{ApprenticeExperience::respond($t,$t->auth_version,$e,$this->response($e));$this->fail('Reassigned actor accepted');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $x){$this->assertSame(403,$x->getStatusCode());}
  try{ApprenticeExperience::create($u,$u->auth_version+1,$this->data());$this->fail('Stale actor accepted');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $x){$this->assertSame(403,$x->getStatusCode());}
 }
 public function test_audit_failure_rolls_back_request_and_response():void {
  [$u,$t]=$this->fixture();$e=$this->create($u);\Tests\Support\AuditFault::install('experience_audit_failure','audit_events','Fictional failure');$this->actingAs($t)->post(route('experience.respond',$e),$this->response($e))->assertStatus(500);$this->assertSame('New',$e->fresh()->state);$this->post('/my-help-and-achievements',$this->data())->assertForbidden();$this->actingAs($u)->post('/my-help-and-achievements',$this->data())->assertStatus(500);$this->assertDatabaseCount('apprentice_experience_entries',1);
 }
 public function test_filter_and_pagination_are_scoped_to_own_records():void {
  [$u,$t]=$this->fixture();for($i=0;$i<16;$i++){$this->create($u);}$this->actingAs($u)->get('/my-help-and-achievements?filter=all')->assertOk()->assertSee('page=2',false);$this->get('/my-help-and-achievements?filter=unknown')->assertSessionHasErrors('filter');
 }
 public function test_daily_limit_is_persisted_but_replay_does_not_consume_new_budget():void {
  [$u]=$this->fixture();$first=null;for($i=0;$i<20;$i++){$first??=$this->create($u);if($i>0){$this->create($u);}}$data=$this->data();$data['request_token']=$first->request_token;$this->actingAs($u)->post('/my-help-and-achievements',$data)->assertRedirect()->assertSessionHasNoErrors();$this->post('/my-help-and-achievements',$this->data())->assertSessionHasErrors('detail');$this->assertDatabaseCount('apprentice_experience_entries',20);
 }
 public function test_photo_requires_explanation_and_image_type_and_keeps_private_task_scope():void {
  [$u,$t,$a]=$this->fixture();$task=LearningTask::factory()->create(['apprentice_id'=>$a->id]);Storage::fake('local');$this->actingAs($u);
  $this->post(route('tasks.upload',$task),['version'=>1,'kind'=>'workplace-photo','file'=>UploadedFile::fake()->image('job.jpg')])->assertSessionHasErrors('description');
  $this->post(route('tasks.upload',$task),['version'=>1,'kind'=>'workplace-photo','description'=>'Test','file'=>UploadedFile::fake()->create('job.pdf',1,'application/pdf')])->assertSessionHasErrors('file');
  $this->post(route('tasks.upload',$task),['version'=>1,'kind'=>'workplace-photo','description'=>'Photo of my inspection work','file'=>UploadedFile::fake()->image('job.jpg')])->assertRedirect()->assertSessionHasNoErrors();$task->refresh();$e=$task->evidence->first();$this->assertSame('Photo of my inspection work',$e->description);$this->assertSame('Draft',$task->status);$this->assertSame(2,$task->version);$this->assertSame('local',$e->disk);Storage::disk('local')->assertExists($e->path);
  $this->actingAs($t)->get(route('tasks.show',$task))->assertOk()->assertSee('Photo of my inspection work');$this->get(route('tasks.evidence.download',[$task,$e]))->assertOk();$other=User::factory()->create(['role'=>'apprentice']);$this->actingAs($other)->get(route('tasks.evidence.download',[$task,$e]))->assertForbidden();
 }
 public function test_photo_stale_locked_and_unreleased_uploads_make_no_record():void {
  [$u,$t,$a]=$this->fixture();$task=LearningTask::factory()->create(['apprentice_id'=>$a->id]);$data=fn($v)=>['version'=>$v,'kind'=>'workplace-photo','description'=>'My photo','file'=>UploadedFile::fake()->image('job.png')];$this->actingAs($u)->post(route('tasks.upload',$task),$data(99))->assertSessionHasErrors('version');$task->status='Awaiting marking';$task->save();$this->post(route('tasks.upload',$task),$data(1))->assertSessionHasErrors('file');$task->status='Draft';$task->block=2;$task->save();LearningBlockControl::create(['apprentice_id'=>$a->id,'enabled'=>true,'exceptions'=>[],'history'=>[]]);$this->post(route('tasks.upload',$task),$data(1))->assertSessionHasErrors('block');$task->block=1;$task->template=['kind'=>'source-pending'];$task->save();$this->post(route('tasks.upload',$task),$data(1))->assertSessionHasErrors('file');$this->assertDatabaseCount('task_evidence',0);
 }
 public function test_camera_form_is_progressive_enhancement_with_no_script_dependency():void {
  [$u,$t,$a]=$this->fixture();$task=LearningTask::factory()->create(['apprentice_id'=>$a->id]);$this->actingAs($u)->get(route('tasks.show',$task))->assertOk()->assertSee('capture="environment"',false)->assertSee('accept="image/jpeg,image/png"',false)->assertSee('What did you do, and how does this photo support the task?');
 }
 public function test_migration_round_trip_keeps_legacy_task_and_file_records():void {
  [$u,$t,$a]=$this->fixture();$task=LearningTask::factory()->create(['apprentice_id'=>$a->id]);$task->answers=['saved'=>'kept'];$task->save();$m=require database_path('migrations/2026_11_14_210000_add_apprentice_experience.php');$m->down();$this->assertFalse(Schema::hasTable('apprentice_experience_entries'));$this->assertFalse(Schema::hasColumn('task_evidence','description'));$m->up();$this->assertTrue(Schema::hasColumn('task_evidence','description'));$this->assertSame(['saved'=>'kept'],$task->fresh()->answers);$this->assertTrue(Schema::hasTable('apprentice_mobile_challenges'));
 }
 public function test_validation_retains_private_help_kind_and_does_not_cross_fill_achievement_form():void {
  [$u]=$this->fixture();$this->actingAs($u)->from('/my-help-and-achievements')->post('/my-help-and-achievements',array_replace($this->data('help_private'),['title'=>'']))->assertRedirect();$this->get('/my-help-and-achievements')->assertOk()->assertSee('value="help_private" selected',false);
  $data=array_replace($this->data('achievement'),['happened_on'=>null,'detail'=>'ACHIEVEMENT-ONLY-TEXT']);$this->from('/my-help-and-achievements')->post('/my-help-and-achievements',$data)->assertRedirect();$html=$this->get('/my-help-and-achievements')->assertOk()->getContent();$dom=new \DOMDocument();$dom->loadHTML($html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$this->assertSame('',$dom->getElementById('help-detail')->textContent);$this->assertSame('ACHIEVEMENT-ONLY-TEXT',$dom->getElementById('achievement-detail')->textContent);
 }
 public function test_recorded_block_milestone_uses_latest_of_all_five_actual_decision_dates():void {
  [$u,$t,$a]=$this->fixture();foreach(\App\Support\TaskCatalogue::tasks('autocare')as$item){if((int)$item['block']!==1){continue;}$task=LearningTask::factory()->create(['apprentice_id'=>$a->id,'reference'=>$item['id'],'template'=>\App\Support\TaskCatalogue::template($item['id']),'status'=>'Approved']);$task->submissions=[['decision'=>['date'=>'2026-11-01','feedback'=>'Fictional approval']]];$task->save();}
  $events=collect(JourneyEvents::for($a))->where('type','Training block tasks approved');$this->assertCount(1,$events);$this->assertSame('2026-11-01',$events->first()['date']);$task->submissions=[];$task->save();$this->assertCount(0,collect(JourneyEvents::for($a))->where('type','Training block tasks approved'));
 }

}
