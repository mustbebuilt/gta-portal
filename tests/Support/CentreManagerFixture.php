<?php
namespace Tests\Support;
use App\Models\{User,TutorGroup,Apprentice,LearningTask,ProgrammeOutcome};
use App\Support\{CentreManagerAccess as A,CentreOversight as O,CentreFollowups as F};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
trait CentreManagerFixture {
 private function f():array {
  $this->travelTo(now()->setDate(2026,11,15)->setTime(10,0));$admin=User::factory()->create(['role'=>'admin']);$checker=User::factory()->create(['role'=>'admin']);$manager=User::factory()->create(['role'=>'manager']);$tutor=User::factory()->create(['role'=>'tutor']);$group=TutorGroup::factory()->create(['tutor_id'=>$tutor->id,'active'=>true]);$otherGroup=TutorGroup::factory()->create(['tutor_id'=>$tutor->id,'active'=>true]);$a=Apprentice::factory()->create(['tutor_group_id'=>$group->id,'tutor_id'=>$tutor->id,'first_name'=>'PRIVATE-APPRENTICE49']);$outside=Apprentice::factory()->create(['tutor_group_id'=>$otherGroup->id,'tutor_id'=>$tutor->id,'reference'=>'PRIVATE-OUTSIDE49']);ProgrammeOutcome::factory()->create(['apprentice_id'=>$a->id,'context'=>['status'=>'Active','sourceRef'=>'PRIVATE-OUTCOME49','ehcp'=>'Yes','reason'=>'PRIVATE-SUPPORT49']]);$task=LearningTask::factory()->create(['apprentice_id'=>$a->id,'due_date'=>today()->subDay(),'status'=>'Draft','template'=>['kind'=>'task'],'answers'=>['note'=>'PRIVATE-ANSWER49']]);$from=today()->subDays(70)->toDateString();$to=today()->toDateString();return compact('admin','checker','manager','tutor','group','otherGroup','a','outside','task','from','to');
 }
 private function proposal(array $f,array $areas=['training','coordination'],?string $token=null,bool $protected=false):\App\Models\CentreManagerAccessRequest {$groups=[$f['group']->id];$expires=today()->addDays(90)->toDateString();$v=A::preview($f['admin'],$f['manager'],$groups,$areas,$expires,$protected);return A::prepare($f['admin'],1,$f['manager'],$groups,$areas,$expires,$protected,$v['source_hash'],$token??(string)Str::uuid(),'PRIVATE-ACCESS-REASON49');}
 private function granted(array $f,array $areas=['training','coordination'],bool $protected=false):\App\Models\CentreManagerGrant {$r=$this->proposal($f,$areas,null,$protected);A::review($f['checker'],1,$r,1,true,'PRIVATE-INDEPENDENT-REVIEW49');return A::apply($f['admin'],1,$r->fresh(),2);}
 private function work(array $f,?string $token=null):\App\Models\CentreManagerFollowup {$p=O::position($f['manager'],$f['a'],'training',$f['from'],$f['to']);return F::create($f['manager'],1,$f['a'],'training','task_deadline',$f['from'],$f['to'],$p['source_hash'],$f['tutor'],'tutor',$token??(string)Str::uuid(),today()->addDays(7)->toDateString(),'PRIVATE-OPERATIONAL-REF49');}
 private function reject(callable $fn):void {try{$fn();$this->fail('Expected checked refusal');}catch(ValidationException $e){$this->assertNotEmpty($e->errors());}}
}
