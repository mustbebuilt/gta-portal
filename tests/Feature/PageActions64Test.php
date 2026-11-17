<?php
namespace Tests\Feature;
use App\Models\{User,Apprentice,LearningTask,StaffDuty,Employer,SmartAction};
use App\Support\EmployerActions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;
class PageActions64Test extends TestCase {
 use LazilyRefreshDatabase, \Tests\Support\CentreManagerFixture;
 public function test_profile_has_one_primary_and_preserves_each_role_scope():void {
  foreach(['admin','ceo','tutor','apprentice'] as $role){
   $u=User::factory()->create(['role'=>$role]);$a=Apprentice::factory()->create(['tutor_id'=>$role==='tutor'?$u->id:null,'user_id'=>$role==='apprentice'?$u->id:null]);
   $r=$this->actingAs($u)->get(route('apprentices.show',$a))->assertOk()->assertSee('More actions');
   $dom=new \DOMDocument();@$dom->loadHTML($r->getContent());$xp=new \DOMXPath($dom);
   $this->assertCount(1,$xp->query('//nav[contains(@class,"page-actions64")]//a[contains(concat(" ",normalize-space(@class)," ")," primary ")]'));
   if(in_array($role,['admin','ceo'])){$r->assertSee('Set up apprentice sign-in');}else{$r->assertDontSee('Set up apprentice sign-in')->assertDontSee(route('apprentices.edit',$a),false);}
   if($role==='tutor'||$role==='apprentice'){$this->get(route('apprentices.edit',$a))->assertForbidden();$outside=Apprentice::factory()->create();$this->get(route('apprentices.show',$outside))->assertForbidden();}
  }
 }
 public function test_assigned_dual_duty_tutor_can_find_existing_careers_workspace():void {
  $u=User::factory()->create(['role'=>'tutor']);StaffDuty::create(['user_id'=>$u->id,'careers_adviser'=>true,'history'=>[]]);$a=Apprentice::factory()->create(['tutor_id'=>$u->id,'careers_adviser_id'=>$u->id]);
  $this->actingAs($u)->get(route('apprentices.show',$a))->assertOk()->assertSee(route('careers.index',$a),false);$this->get(route('careers.index',$a))->assertOk();
  $a->careers_adviser_id=null;$a->save();$this->get(route('apprentices.show',$a))->assertDontSee(route('careers.index',$a),false);$this->get(route('careers.index',$a))->assertForbidden();
 }
 public function test_next_task_precedes_secondary_reports_and_signoff_remains_visible():void {
  $u=User::factory()->create(['role'=>'apprentice']);$a=Apprentice::factory()->create(['user_id'=>$u->id]);LearningTask::factory()->create(['apprentice_id'=>$a->id]);
  $s=$this->actingAs($u)->get('/my-next-step')->assertOk()->assertSee('Reviews waiting for my sign-off')->getContent();
  $this->assertLessThan(strpos($s,'My report: charts'),strpos($s,'experience-next'));
 }
 public function test_reference_preview_export_uses_only_disposable_test_data():void {
  $dir=getenv('GTA_PREVIEW64_DIR');if(!$dir){$this->markTestSkipped('Preview export requested separately.');}if(!is_dir($dir)){mkdir($dir,0700,true);}
  fake()->seed(6400);fake()->unique(true);$this->travelTo(\Carbon\Carbon::parse('2026-11-17 12:00:00'));
  $admin=User::factory()->create(['role'=>'admin','name'=>'Fictional Administrator']);$tutor=User::factory()->create(['role'=>'tutor','name'=>'Fictional Tutor']);$learner=User::factory()->create(['role'=>'apprentice','name'=>'Fictional Apprentice']);
  $a=Apprentice::factory()->create(['tutor_id'=>$tutor->id,'user_id'=>$learner->id,'first_name'=>'Fictional Alexandra','last_name'=>'Longname-With-Multiple-Parts-For-Layout-Checks','email'=>'fictional.long.apprentice@example.invalid']);
  // Large list fixture: 100 profiles and 60 tasks on the representative record.
  Apprentice::factory()->count(99)->create();
  for($i=1;$i<=60;$i++){LearningTask::factory()->create(['apprentice_id'=>$a->id,'reference'=>'PREVIEW64-'.$i,'title'=>'Fictional task '.$i.' – inspect and record workplace findings with supporting evidence','block'=>(int)ceil($i/5)]);}
  foreach([['admin-profile',$admin,route('apprentices.show',$a)],['tutor-profile',$tutor,route('apprentices.show',$a)],['apprentice-profile',$learner,route('apprentices.show',$a)],['apprentice-home',$learner,'/my-next-step'],['tutor-tasks',$tutor,route('learning.index',$a)],['admin-list',$admin,'/apprentices'],['admin-form',$admin,route('apprentices.edit',$a)]] as [$name,$u,$url]){$html=$this->actingAs($u)->get($url)->assertOk()->getContent();file_put_contents($dir.'/'.$name.'.html',$html);}
  $contact=User::factory()->create(['role'=>'employer','name'=>'Fictional Employer']);$e=Employer::factory()->create();$a->employer_id=$e->id;$a->save();$g=EmployerActions::grant($admin,$admin->auth_version,$a,['user_id'=>$contact->id,'version'=>0,'identity_reference'=>'Fictional independent authority check']);
  $target=SmartAction::factory()->create(['apprentice_id'=>$a->id,'owner'=>'Employer / workplace mentor']);EmployerActions::publish($admin,$admin->auth_version,$a,['request_token'=>(string)\Illuminate\Support\Str::uuid(),'grant_id'=>$g->id,'kind'=>'target','source_id'=>$target->id,'title'=>'Fictional workplace target','detail'=>'Confirm the agreed workplace action','due_date'=>today()->addDays(7)->toDateString()]);
  $f=$this->f();$this->granted($f);file_put_contents($dir.'/manager-home.html',$this->actingAs($f['manager'])->get('/centre-oversight')->assertOk()->getContent());
  file_put_contents($dir.'/employer-home.html',$this->actingAs($contact)->get(route('employer-actions.index'))->assertOk()->getContent());
 }
}
