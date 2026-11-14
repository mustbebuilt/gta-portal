<?php
namespace Tests\Feature;
use App\Models\{Apprentice,Qualification,TrainingTopic,StaffDuty,User};
use App\Support\{StaffDuties,LearningAccess,EnglishMathsFollowup};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\{DB,Schema};
use Tests\TestCase;
class SubjectStaffDutiesTest extends TestCase {
 use LazilyRefreshDatabase;
 private function grant(User $u,User $admin,array $flags):void {
  StaffDuties::save($u,$admin,$admin->auth_version,$u->fresh()->record_version,StaffDuty::find($u->id)?->version??0,$flags);
 }
 private function fixture():array {
  $admin=User::factory()->create(['role'=>'admin']);$u=User::factory()->create(['role'=>'staff']);
  $a=Apprentice::factory()->create(['maths_tutor_id'=>$u->id,'english_tutor_id'=>$u->id,'reference'=>'ASSIGNED-SUBJECTS']);
  $other=Apprentice::factory()->create(['reference'=>'UNRELATED-SUBJECTS']);return [$admin,$u,$a,$other];
 }
 public function test_grant_requires_assignment_and_preserves_account():void {
  [$admin,$u,$a,$other]=$this->fixture();$count=User::count();$pass=$u->password;
  $this->assertFalse(LearningAccess::subject($u,$a,'maths'));$this->grant($u,$admin,['maths_tutor'=>true]);
  $this->assertTrue(LearningAccess::subject($u,$a,'maths'));$this->assertFalse(LearningAccess::subject($u,$a,'english'));
  $this->assertFalse(LearningAccess::subject($u,$other,'maths'));$this->assertFalse(LearningAccess::subject($u,$a,'unknown'));
  $this->assertSame($count,User::count());$this->assertSame('staff',$u->fresh()->role);$this->assertSame($pass,$u->fresh()->password);
  $this->assertFalse(\App\Support\SupportAccess::team($u->fresh()));
 }
 public function test_primary_subject_scope_and_added_subject_form_correct_union():void {
  [$admin,$u,$a]=$this->fixture();$u->forceFill(['role'=>'functional_skills_tutor','subject_scope'=>'english'])->save();
  $this->assertFalse(StaffDuties::has($u,'maths_tutor'));$this->assertTrue(StaffDuties::has($u,'english_tutor'));
  $this->grant($u,$admin,['maths_tutor'=>true]);$this->assertTrue(LearningAccess::subject($u,$a,'maths'));$this->assertTrue(LearningAccess::subject($u,$a,'english'));
  $this->grant($u,$admin,[]);$this->assertFalse(LearningAccess::subject($u,$a,'maths'));$this->assertTrue(LearningAccess::subject($u,$a,'english'));
 }
 public function test_caseload_and_topics_hide_other_subject_and_unrelated_people():void {
  [$admin,$u,$a,$other]=$this->fixture();$this->grant($u,$admin,['maths_tutor'=>true]);
  $m=TrainingTopic::factory()->create(['apprentice_id'=>$a->id,'topic'=>'MATHS-PERMITTED']);$e=TrainingTopic::factory()->create(['apprentice_id'=>$a->id,'subject'=>'english','topic'=>'ENGLISH-PRIVATE']);
  $this->actingAs($u->fresh())->get('/maths-english')->assertOk()->assertSee('ASSIGNED-SUBJECTS')->assertDontSee('UNRELATED-SUBJECTS');
  $this->get('/apprentices/'.$a->id.'/maths-english')->assertOk()->assertSee('MATHS-PERMITTED')->assertDontSee('ENGLISH-PRIVATE');
  $this->get('/topics/'.$m->id)->assertOk();$this->get('/topics/'.$e->id)->assertForbidden();
  $this->get('/apprentices/'.$other->id.'/maths-english')->assertForbidden();$this->get('/apprentices/'.$a->id.'/maths-english?subject=english')->assertForbidden();$this->get('/directories/staff')->assertForbidden();
 }
 public function test_subject_updates_do_not_automatically_achieve_qualification():void {
  [$admin,$u,$a]=$this->fixture();$this->grant($u,$admin,['maths_tutor'=>true]);
  $m=TrainingTopic::factory()->create(['apprentice_id'=>$a->id]);$e=TrainingTopic::factory()->create(['apprentice_id'=>$a->id,'subject'=>'english']);$q=Qualification::factory()->create(['apprentice_id'=>$a->id,'subject'=>'maths','status'=>'Working towards']);
  $data=['version'=>1,'status'=>'Completed','completion_date'=>today()->toDateString(),'feedback'=>'Fictional completed training'];
  $this->actingAs($u->fresh())->post('/topics/'.$m->id.'/update',$data)->assertRedirect()->assertSessionHasNoErrors();$this->post('/topics/'.$e->id.'/update',$data)->assertForbidden();
  $this->assertSame('Completed',$m->fresh()->status);$this->assertSame('Working towards',$q->fresh()->status);$this->assertSame('Not Started',$e->fresh()->status);
 }
 public function test_qualification_write_requires_correct_subject_and_cannot_relabel_other_subject():void {
  [$admin,$u,$a]=$this->fixture();$this->grant($u,$admin,['maths_tutor'=>true]);
  $data=['subject'=>'maths','type'=>'Functional Skills','title'=>'Fictional FS Maths','level'=>'Level 2','status'=>'Working towards','start_date'=>today()->toDateString()];
  $this->actingAs($u->fresh())->post('/apprentices/'.$a->id.'/qualifications',$data)->assertRedirect()->assertSessionHasNoErrors();$this->post('/apprentices/'.$a->id.'/qualifications',[...$data,'subject'=>'english'])->assertForbidden();
  $e=Qualification::factory()->create(['apprentice_id'=>$a->id,'subject'=>'english']);$this->put('/apprentices/'.$a->id.'/qualifications/'.$e->id,[...$data,'version'=>1])->assertForbidden();$this->assertDatabaseCount('qualifications',2);$this->assertSame('english',$e->fresh()->subject);
 }
 public function test_main_tutor_union_is_unique_and_subject_write_separate():void {
  [$admin,$u,$a]=$this->fixture();$u->forceFill(['role'=>'tutor'])->save();$own=Apprentice::factory()->create(['tutor_id'=>$u->id,'maths_tutor_id'=>$u->id]);$this->grant($u,$admin,['maths_tutor'=>true]);
  $this->assertSame(['maths','english'],EnglishMathsFollowup::subjects($u->fresh(),$own));$this->assertSame(['maths'],EnglishMathsFollowup::subjects($u->fresh(),$a));$this->assertFalse(LearningAccess::subject($u,$own,'english'));
  $r=$this->actingAs($u->fresh())->get('/maths-english')->assertOk();$this->assertCount(2,$r->viewData('followupRows')->items());
 }
 public function test_selectors_and_presence_query_share_subject_eligibility():void {
  [$admin,$u]=$this->fixture();$this->grant($u,$admin,['maths_tutor'=>true]);$english=User::factory()->create(['role'=>'functional_skills_tutor','subject_scope'=>'english']);$both=User::factory()->create(['role'=>'functional_skills_tutor','subject_scope'=>'both']);$off=User::factory()->create(['role'=>'functional_skills_tutor','subject_scope'=>'maths','active'=>false]);
  $ids=StaffDuties::query(User::query(),'maths_tutor')->pluck('id')->all();$this->assertContains($u->id,$ids);$this->assertContains($both->id,$ids);$this->assertNotContains($english->id,$ids);$this->assertNotContains($off->id,$ids);
  $db=StaffDuties::query(DB::table('users'),'maths_tutor')->pluck('id')->all();sort($db);sort($ids);$this->assertSame($ids,$db);$this->actingAs($admin)->get('/apprentices/create')->assertOk()->assertSee($u->name);
 }
 public function test_management_form_accepts_four_duties_and_rejects_unknown_keys():void {
  [$admin,$u]=$this->fixture();$this->actingAs($admin)->post('/staff/'.$u->id.'/duties',['record_version'=>$u->record_version,'duty_version'=>0,'duties'=>['maths_tutor'=>1,'english_tutor'=>1,'mentor'=>1,'careers_adviser'=>1]])->assertRedirect()->assertSessionHasNoErrors();
  $row=StaffDuty::findOrFail($u->id);$this->assertTrue($row->maths_tutor);$this->assertTrue($row->english_tutor);$this->assertTrue($row->mentor);$this->assertTrue($row->careers_adviser);
  $this->get('/directories/staff/'.$u->id)->assertOk()->assertSee('Maths tutor')->assertSee('English tutor');$this->post('/staff/'.$u->id.'/duties',['record_version'=>$u->fresh()->record_version,'duty_version'=>$row->version,'duties'=>['admin'=>true]])->assertSessionHasErrors('duties');
 }
 public function test_revoke_preserves_assignments_work_and_other_duties():void {
  [$admin,$u,$a]=$this->fixture();$this->grant($u,$admin,['maths_tutor'=>true,'careers_adviser'=>true]);$t=TrainingTopic::factory()->create(['apprentice_id'=>$a->id]);$old=$u->fresh()->auth_version;$this->grant($u,$admin,['careers_adviser'=>true]);
  $this->assertGreaterThan($old,$u->fresh()->auth_version);$this->assertTrue(StaffDuties::has($u,'careers_adviser'));$this->assertFalse(LearningAccess::subject($u,$a,'maths'));$this->assertSame($u->id,$a->fresh()->maths_tutor_id);$this->assertNotNull($t->fresh());$this->actingAs($u->fresh())->get('/topics/'.$t->id)->assertForbidden();
 }
 public function test_migration_round_trip_preserves_existing_duties_and_history():void {
  [$admin,$u,$a]=$this->fixture();$this->grant($u,$admin,['mentor'=>true]);$before=StaffDuty::find($u->id)->history;$migration=require database_path('migrations/2026_11_14_140000_add_subject_staff_duties.php');$migration->down();$this->assertFalse(Schema::hasColumn('staff_duties','maths_tutor'));$migration->up();$row=StaffDuty::find($u->id);$this->assertTrue($row->mentor);$this->assertFalse($row->maths_tutor);$this->assertSame($before,$row->history);$this->assertNotNull($a->fresh());
 }
}
