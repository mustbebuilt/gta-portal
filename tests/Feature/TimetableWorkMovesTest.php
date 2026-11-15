<?php
namespace Tests\Feature;
use App\Models\{User,TimetablePlan as Plan,TimetablePlanEntry as Entry};
use App\Support\{CentrePlanning as P,TimetableWorkMoves as M,ReviewedImports};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\AuditFault;
use Tests\TestCase;
class TimetableWorkMovesTest extends TestCase
{
 use LazilyRefreshDatabase;
 private function f():array{
  $this->travelTo(now()->setDate(2026,11,15)->setTime(10,0));$admin=User::factory()->create(['role'=>'admin']);$tutor=User::factory()->create(['role'=>'tutor']);$dates=[];for($i=0;$i<14;$i++){$dates[]=today()->addDays($i)->toDateString();}
  P::windows($admin,1,$tutor,$dates,ReviewedImports::hash(P::windowSource($tutor,$dates)),true,'09:00','17:00');
  $args=['staff_id'=>$tutor->id,'date'=>today()->addDay()->toDateString(),'weeks'=>1,'start'=>'10:00','end'=>'11:00','kind'=>'Marking','site'=>'Remote','room_id'=>null,'group_id'=>null,'headcount'=>null,'title'=>'Fictional marking allocation'];
  $p=P::save($admin,1,null,0,(string)Str::uuid(),$args);P::check($admin,1,$p,1,true);P::publish($admin,1,$p->refresh(),2);return compact('admin','tutor','args','p','dates');
 }
 private function move(array $f,?string $token=null):Plan{$v=M::preview($f['admin'],$f['p']);return M::apply($f['admin'],1,$f['p'],3,$v['hash'],$v['options'][0]['choice'],$token??(string)Str::uuid(),'Checked continuity ref',true);}
 private function reject(callable $fn):void{try{$fn();$this->fail('Expected checked refusal');}catch(ValidationException $e){$this->assertNotEmpty($e->errors());}}
 public function test_move_preserves_work_and_retains_original_published_history():void{
  $f=$this->f();$new=$this->move($f);$this->assertSame('Withdrawn',$f['p']->fresh()->state);$this->assertTrue(P::published($new));$before=$f['args'];$after=$new->source['data']['args'];foreach(['staff_id','kind','site','title','room_id']as$key){$this->assertSame($before[$key],$after[$key]);}$this->assertSame(60,P::minutes($after['end'])-P::minutes($after['start']));$this->assertDatabaseCount('training_sessions',0);$this->assertDatabaseCount('learning_tasks',0);$this->assertSame(1,DB::table('timetable_planning_events')->where('event','work_rescheduled')->count());
 }
 public function test_exact_replay_returns_same_replacement_without_another_withdrawal():void{
  $f=$this->f();$v=M::preview($f['admin'],$f['p']);$token=(string)Str::uuid();$args=[$f['admin'],1,$f['p'],3,$v['hash'],$v['options'][0]['choice'],$token,'Checked continuity ref',true];$a=M::apply(...$args);$b=M::apply(...$args);$this->assertSame($a->id,$b->id);$this->assertDatabaseCount('timetable_plans',2);
 }
 public function test_changed_choice_or_reason_cannot_reuse_request():void{
  $f=$this->f();$v=M::preview($f['admin'],$f['p']);$token=(string)Str::uuid();M::apply($f['admin'],1,$f['p'],3,$v['hash'],$v['options'][0]['choice'],$token,'Checked continuity ref',true);$this->reject(fn()=>M::apply($f['admin'],1,$f['p'],3,$v['hash'],$v['options'][1]['choice'],$token,'Changed continuity ref',true));
 }
 public function test_preview_is_read_only_and_lists_no_exact_original_slot():void{
  $f=$this->f();$before=DB::table('timetable_planning_events')->count();$v=M::preview($f['admin'],$f['p']);$this->assertNotEmpty($v['options']);$this->assertLessThanOrEqual(60,count($v['options']));$this->assertNotContains($f['args']['date'].' '.$f['args']['start'],array_column($v['options'],'choice'));$this->assertSame($before,DB::table('timetable_planning_events')->count());
 }
 public function test_busy_snapshot_change_holds_old_preview_without_withdrawing_original():void{
  $f=$this->f();$v=M::preview($f['admin'],$f['p']);$d=array_replace($f['args'],['date'=>$v['options'][0]['date'],'start'=>$v['options'][0]['start'],'end'=>$v['options'][0]['end']]);$p=P::save($f['admin'],1,null,0,(string)Str::uuid(),$d);P::check($f['admin'],1,$p,1,true);P::publish($f['admin'],1,$p->refresh(),2);$this->reject(fn()=>M::apply($f['admin'],1,$f['p'],3,$v['hash'],$v['options'][0]['choice'],(string)Str::uuid(),'Checked continuity ref',true));$this->assertSame('Published',$f['p']->fresh()->state);
 }
 public function test_arbitrary_slot_and_stale_version_are_rejected():void{
  $f=$this->f();$v=M::preview($f['admin'],$f['p']);$this->reject(fn()=>M::apply($f['admin'],1,$f['p'],3,$v['hash'],'2026-11-16 01:00',(string)Str::uuid(),'Checked continuity ref',true));$this->reject(fn()=>M::apply($f['admin'],1,$f['p'],2,$v['hash'],$v['options'][0]['choice'],(string)Str::uuid(),'Checked continuity ref',true));$this->assertDatabaseCount('timetable_plans',1);
 }
 public function test_working_window_change_invalidates_current_source():void{
  $f=$this->f();P::windows($f['admin'],1,$f['tutor'],[$f['args']['date']],ReviewedImports::hash(P::windowSource($f['tutor'],[$f['args']['date']])),true,'08:00','17:00');$this->reject(fn()=>M::preview($f['admin'],$f['p']));
 }
 public function test_unverified_dates_are_not_free_capacity():void{
  $f=$this->f();DB::table('timetable_staff_windows')->whereDate('work_date','!=',$f['args']['date'])->delete();$v=M::preview($f['admin'],$f['p']);$this->assertNotEmpty($v['unknown_dates']);foreach($v['options']as$o){$this->assertSame($f['args']['date'],$o['date']);}
 }
 public function test_register_link_and_multi_occurrence_series_are_excluded():void{
  $f=$this->f();$e=Entry::where('plan_id',$f['p']->id)->first();$e->link_proof=['fictional'=>true];$e->save();$this->reject(fn()=>M::preview($f['admin'],$f['p']));
 }
 public function test_repeated_series_must_use_original_planner():void{
  $f=$this->f();$a=array_replace($f['args'],['weeks'=>2,'start'=>'12:00','end'=>'13:00']);$p=P::save($f['admin'],1,null,0,(string)Str::uuid(),$a);P::check($f['admin'],1,$p,1,true);P::publish($f['admin'],1,$p->refresh(),2);$this->reject(fn()=>M::preview($f['admin'],$p->refresh()));
 }
 public function test_audit_failure_rolls_back_both_original_and_replacement():void{
  $f=$this->f();$before=DB::table('timetable_planning_events')->count();AuditFault::install('move_audit_fail','timetable_planning_events','fictional audit failure','work_rescheduled');try{$this->move($f);$this->fail('Expected database failure');}catch(\Illuminate\Database\QueryException){}$this->assertSame('Published',$f['p']->fresh()->state);$this->assertDatabaseCount('timetable_plans',1);$this->assertDatabaseCount('timetable_plan_entries',1);$this->assertSame($before,DB::table('timetable_planning_events')->count());
 }
 #[\PHPUnit\Framework\Attributes\TestWith(['tutor'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['staff'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['apprentice'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['employer'])]
 public function test_viewers_and_unrelated_roles_cannot_move_work(string $role):void{$f=$this->f();$u=User::factory()->create(['role'=>$role]);$this->actingAs($u)->get('/centre-planning/work-moves')->assertForbidden();$this->get('/centre-planning/work-moves/'.$f['p']->id)->assertForbidden();}
 public function test_real_form_requires_confirmation_and_preserves_request_version():void{$f=$this->f();$this->actingAs($f['admin'])->get('/centre-planning/work-moves/'.$f['p']->id)->assertOk()->assertSee('Preserve 60 minutes')->assertHeader('Referrer-Policy','no-referrer');$this->postJson('/centre-planning/work-moves/'.$f['p']->id,[])->assertUnprocessable();}
}
