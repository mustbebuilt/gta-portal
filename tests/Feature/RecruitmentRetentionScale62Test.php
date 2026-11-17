<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\{DB,Crypt};
use App\Models\{Apprentice,User,ApprenticeEntryContext as Entry,ProgrammeOutcome,DestinationMilestone as Milestone};
class RecruitmentRetentionScale62Test extends TestCase {
 use LazilyRefreshDatabase;
 public function test_report_and_paginated_queues_at_realistic_history_volume():void {
  $u=User::factory()->create(['role'=>'admin']);$people=Apprentice::factory()->count(1000)->create(['start_date'=>'2024-01-01']);$now=now();
  $entry=[];$outcomes=[];$tasks=[];$concerns=[];$history=[];$assignment=[];
  $event=Crypt::encryptString(json_encode(['after'=>['family_owned'=>'Unknown'],'reason'=>'Synthetic volume fixture']));
  foreach($people as $i=>$a){
   $entry[]=['apprentice_id'=>$a->id,'recruitment_route'=>$i%2?'Social media':'School or careers adviser','employment_route'=>'Employer recruited them directly','stage'=>'Application','updated_by'=>$u->id,'created_at'=>$now,'updated_at'=>$now];
   $outcomes[]=['apprentice_id'=>$a->id,'context'=>json_encode(['status'=>$i%2?'Completed':'Withdrawn','achievement'=>$i%2?'Achieved':'Unknown','endDate'=>'2025-01-01','sourceRef'=>'Synthetic exit']),'version'=>1,'created_at'=>$now,'updated_at'=>$now];
   for($j=0;$j<50;$j++){$tasks[]=['apprentice_id'=>$a->id,'reference'=>'T'.$j,'block'=>1,'title'=>'Synthetic volume task','theme'=>'Synthetic','template'=>'{}','status'=>$j%2?'Approved':'Draft','created_at'=>$now,'updated_at'=>$now];}
   for($j=0;$j<20;$j++){$history[]=['apprentice_id'=>$a->id,'actor_id'=>$u->id,'kind'=>'Employment','effective_on'=>'2024-01-01','revision'=>1,'payload'=>$event,'created_at'=>$now,'updated_at'=>$now];}
   for($j=0;$j<2;$j++){$concerns[]=['apprentice_id'=>$a->id,'raised_by'=>$u->id,'initial_note'=>'Synthetic concern','code'=>'IM1','status'=>'Closed','acknowledged_at'=>'2024-02-01','closed_at'=>'2024-02-10','created_at'=>'2024-02-01','updated_at'=>$now];}
   $assignment[]=['apprentice_id'=>$a->id,'actor_id'=>$u->id,'before_links'=>null,'after_links'=>'{}','created_at'=>$now];
  }
  foreach(['apprentice_entry_contexts'=>$entry,'programme_outcomes'=>$outcomes,'learning_tasks'=>$tasks,'entry_context_events'=>$history,'support_concerns'=>$concerns,'assignment_change_events'=>$assignment] as $table=>$rows){foreach(array_chunk($rows,200) as $chunk){DB::table($table)->insert($chunk);}}
  $milestones=[];foreach($people as $a){foreach([3,6,12] as $months){$milestones[]=['apprentice_id'=>$a->id,'exit_on'=>'2025-01-01','months'=>$months,'due_on'=>\Carbon\Carbon::parse('2025-01-01')->addMonthsNoOverflow($months)->toDateString(),'state'=>'Open','owner_id'=>$u->id,'created_at'=>$now,'updated_at'=>$now];}}
  foreach(array_chunk($milestones,200) as $chunk){DB::table('destination_milestones')->insert($chunk);}
  $checks=[];foreach(Milestone::all() as $t){$checks[]=['milestone_id'=>$t->id,'actor_id'=>$u->id,'observed_on'=>'2026-01-01','result'=>'Reached','destination'=>'New employer','basis'=>'Reported','continuity'=>'Unknown','source'=>Crypt::encryptString('Synthetic check'),'created_at'=>$now,'updated_at'=>$now];}
  foreach(array_chunk($checks,200) as $chunk){DB::table('destination_milestone_checks')->insert($chunk);}
  unset($entry,$outcomes,$tasks,$concerns,$history,$assignment,$milestones,$checks);
  $this->actingAs($u);DB::enableQueryLog();$start=hrtime(true);$res=$this->get('/reports/retention?factor=recruitment_route');$elapsed=(hrtime(true)-$start)/1e6;$queries=count(DB::getQueryLog());DB::disableQueryLog();
  $res->assertOk()->assertSee('1000 records included')->assertSee('500 / 500')->assertSee('School or careers adviser');$this->assertLessThan(100,$queries);
  $this->get('/retention-admin')->assertOk()->assertSee('Missing data and entry confirmations');$this->get('/retention-admin?show=followup&mine=1')->assertOk();
  $csv=$this->get('/reports/retention?factor=recruitment_route&export=csv');$csv->assertOk();$this->assertStringContainsString('School or careers adviser',$csv->streamedContent());
  $this->assertDatabaseCount('learning_tasks',50000);$this->assertDatabaseCount('entry_context_events',20000);$this->assertDatabaseCount('destination_milestone_checks',3000);
  $path=getenv('GTA_RETENTION_SCALE_REPORT');if($path){file_put_contents($path,json_encode(['engine'=>DB::getDriverName(),'apprentices'=>1000,'learning_tasks'=>50000,'entry_history'=>20000,'concerns'=>2000,'assignment_history'=>1000,'milestone_tasks'=>3000,'milestone_checks'=>3000,'report_ms'=>round($elapsed,2),'report_queries'=>$queries,'peak_php_memory_bytes'=>memory_get_peak_usage(true),'pagination'=>25,'concurrent_load_test'=>false],JSON_PRETTY_PRINT));}
 }
}
