<?php
namespace Tests\Feature;
use App\Models\{User,Apprentice,LearningTask};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;
class DashboardReadProjectionTest extends TestCase {
 use LazilyRefreshDatabase;
 public function test_dashboard_reads_only_summary_metadata_and_preserves_full_source_and_scoped_totals():void {
  $admin=User::factory()->create(['role'=>'admin']);$tutor=User::factory()->create(['role'=>'tutor']);$a=Apprentice::factory()->create(['tutor_id'=>$tutor->id]);$b=Apprentice::factory()->create();
  $full=['kind'=>'practical','catalogueId'=>'autocare','instructions'=>str_repeat('Fictional full source instructions61 ',10000),'fields'=>[['name'=>'explanation','role'=>'apprentice']]];
  $own=LearningTask::factory()->create(['apprentice_id'=>$a->id,'template'=>$full,'answers'=>['private'=>'FICTIONAL_ANSWER61'],'status'=>'Draft']);$foreign=LearningTask::factory()->create(['apprentice_id'=>$b->id,'status'=>'Approved']);$before=$own->fresh()->getAttributes();
  $response=$this->actingAs($admin)->get(route('dashboard.index'))->assertOk()->assertViewHas('metrics',fn($m)=>$m['tasks']===2&&$m['approved']===1)->assertDontSee('FICTIONAL_ANSWER61');
  $task=$response->viewData('tasks')->firstWhere('id',$own->id);$this->assertSame(['kind'=>'practical','catalogueId'=>'autocare'],$task->template);$this->assertArrayNotHasKey('answers',$task->getAttributes());$this->assertFalse($task->isDirty());$this->assertSame($before,$own->fresh()->getAttributes());
  $this->actingAs($tutor)->get(route('dashboard.index'))->assertOk()->assertViewHas('metrics',fn($m)=>$m['tasks']===1&&$m['approved']===0)->assertViewHas('tasks',fn($rows)=>$rows->pluck('id')->all()===[$own->id]);
  $report=$this->actingAs($admin)->get(route('dashboard.report'))->assertOk()->assertDontSee('FICTIONAL_ANSWER61');$reportTask=$report->viewData('tasks')->firstWhere('id',$own->id);$this->assertSame(['kind'=>'practical','catalogueId'=>'autocare'],$reportTask->template);$this->assertArrayNotHasKey('answers',$reportTask->getAttributes());$this->assertFalse($reportTask->isDirty());$this->assertSame($before,$own->fresh()->getAttributes());
 }
}
