<?php
namespace Tests\Feature;
use App\Models\{Apprentice,LearningTask,User};
use App\Support\TaskDeadlineHistory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TaskDeadlineHistoryTest extends TestCase
{
 use LazilyRefreshDatabase;
 private function fixture():array {
  $tutor=User::factory()->create(['role'=>'tutor']);$a=Apprentice::factory()->create(['tutor_id'=>$tutor->id]);$task=LearningTask::factory()->create(['apprentice_id'=>$a->id]);return [$tutor,$a,$task];
 }
 private function url(LearningTask $task):string {return route('tasks.deadline',$task);}
 public function test_manual_set_move_clear_records_exact_dates_actor_and_versions():void {
  [$tutor,$a,$t]=$this->fixture();$this->actingAs($tutor);
  foreach([[1,'2026-12-01','Initial agreement'],[2,'2026-12-10','Revised agreement'],[3,null,'Date removed pending review']]as[$version,$date,$reason]) {
   $this->post($this->url($t),['version'=>$version,'due_date'=>$date,'reason'=>$reason])->assertRedirect()->assertSessionHasNoErrors();
  }
  $t->refresh();$h=$t->deadline_history;$this->assertCount(3,$h);$this->assertSame([null,'2026-12-01','2026-12-10'],array_column($h,'before'));$this->assertSame(['2026-12-01','2026-12-10',null],array_column($h,'after'));$this->assertSame([$tutor->id,$tutor->id,$tutor->id],array_column($h,'by'));$this->assertSame(['manual','manual','manual'],array_column($h,'origin'));$this->assertSame([2,3,4],array_column($h,'after_version'));$this->assertSame(4,$t->version);$this->assertNull($t->due_date);$this->assertSame('Draft',$t->status);$this->assertDatabaseCount('learning_events',3);
 }
 public function test_identical_date_changes_no_version_history_or_event_even_with_reason():void {
  [$tutor,$a,$t]=$this->fixture();$t->due_date='2026-12-01';$t->save();$before=$t->fresh()->getAttributes();
  $this->actingAs($tutor)->post($this->url($t),['version'=>1,'due_date'=>'2026-12-01','reason'=>'No actual date change'])->assertRedirect()->assertSessionHasNoErrors();$this->assertSame($before,$t->fresh()->getAttributes());$this->assertDatabaseCount('learning_events',0);
 }
 public function test_optional_reason_is_compatible_and_request_cannot_forge_provenance():void {
  [$tutor,$a,$t]=$this->fixture();$this->actingAs($tutor)->post($this->url($t),['version'=>1,'due_date'=>'2026-12-01','origin'=>'approved_plan','by'=>99999,'deadline_history'=>[['fake'=>true]],'source'=>['plan_id'=>999]])->assertRedirect()->assertSessionHasNoErrors();
  $h=$t->fresh()->deadline_history[0];$this->assertSame('manual',$h['origin']);$this->assertSame($tutor->id,$h['by']);$this->assertSame($tutor->name,$h['by_name']);$this->assertNull($h['reason']);$this->assertSame([],$h['source']);
 }
 public function test_stale_invalid_and_oversized_edits_leave_date_and_history_unchanged():void {
  [$tutor,$a,$t]=$this->fixture();$this->actingAs($tutor);$before=$t->fresh()->getAttributes();
  $this->post($this->url($t),['version'=>99,'due_date'=>'2026-12-01'])->assertSessionHasErrors('version');$this->post($this->url($t),['version'=>1,'due_date'=>'not-a-date'])->assertSessionHasErrors('due_date');$this->post($this->url($t),['version'=>1,'due_date'=>'2026-12-01','reason'=>str_repeat('x',2001)])->assertSessionHasErrors('reason');$this->assertSame($before,$t->fresh()->getAttributes());
 }
 public function test_learner_unrelated_tutor_and_subject_duties_cannot_edit_deadlines():void {
  [$tutor,$a,$t]=$this->fixture();$learner=User::factory()->create(['role'=>'apprentice']);$a->user_id=$learner->id;$a->save();
  foreach([$learner,User::factory()->create(['role'=>'tutor']),User::factory()->create(['role'=>'functional_skills_tutor','subject_scope'=>'both']),User::factory()->create(['role'=>'mentor'])]as$u) {$this->actingAs($u)->post($this->url($t),['version'=>1,'due_date'=>'2026-12-01'])->assertForbidden();}
  $this->assertNull($t->fresh()->due_date);$this->assertNull($t->fresh()->deadline_history);
 }
 public function test_history_is_staff_only_escaped_and_preserves_approved_work():void {
  [$tutor,$a,$t]=$this->fixture();$learner=User::factory()->create(['role'=>'apprentice']);$a->user_id=$learner->id;$a->save();$t->status='Approved';$t->answers=['saved'=>'UNCHANGED-ANSWERS'];$t->submissions=[['version'=>1,'decision'=>null,'answers'=>[],'fixture'=>'UNCHANGED-SUBMISSION']];$t->save();
  $this->actingAs($tutor)->post($this->url($t),['version'=>1,'due_date'=>'2026-12-01','reason'=>'<script>PRIVATE-PLANNING-REASON</script>'])->assertRedirect();$this->get(route('tasks.show',$t))->assertOk()->assertSee('Deadline change history')->assertSee('&lt;script&gt;PRIVATE-PLANNING-REASON&lt;/script&gt;',false)->assertDontSee('<script>PRIVATE-PLANNING-REASON</script>',false);
  $this->actingAs($learner)->get(route('tasks.show',$t))->assertOk()->assertDontSee('Deadline change history')->assertDontSee('PRIVATE-PLANNING-REASON');$this->assertSame('Approved',$t->fresh()->status);$this->assertSame(['saved'=>'UNCHANGED-ANSWERS'],$t->fresh()->answers);$this->assertSame([['version'=>1,'decision'=>null,'answers'=>[],'fixture'=>'UNCHANGED-SUBMISSION']],$t->fresh()->submissions);
 }
 public function test_audit_failure_rolls_back_date_history_and_version_atomically():void {
  [$tutor,$a,$t]=$this->fixture();$before=$t->fresh()->getAttributes();\Tests\Support\AuditFault::install('deadline_audit_failure','learning_events','Fictional failure');
  $this->actingAs($tutor)->post($this->url($t),['version'=>1,'due_date'=>'2026-12-01'])->assertStatus(500);$this->assertSame($before,$t->fresh()->getAttributes());
 }
 public function test_current_actor_role_and_auth_version_are_rechecked():void {
  [$tutor,$a,$t]=$this->fixture();$old=$tutor->auth_version;$tutor->auth_version++;$tutor->save();
  try {DB::transaction(fn()=>TaskDeadlineHistory::actor($tutor,$old,$a));$this->fail('Stale account accepted');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(403,$e->getStatusCode());}
  $tutor->role='staff';$tutor->save();try {DB::transaction(fn()=>TaskDeadlineHistory::actor($tutor,$tutor->auth_version,$a));$this->fail('Changed role accepted');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(403,$e->getStatusCode());}
 }
 public function test_history_migration_round_trip_keeps_current_dates_and_saved_work():void {
  [$tutor,$a,$t]=$this->fixture();$this->actingAs($tutor)->post($this->url($t),['version'=>1,'due_date'=>'2026-12-01'])->assertRedirect();$migration=require database_path('migrations/2026_11_14_170000_add_task_deadline_history.php');$migration->down();$migration->up();$t->refresh();$this->assertSame('2026-12-01',$t->due_date->toDateString());$this->assertSame('Draft',$t->status);$this->assertNull($t->deadline_history);$this->assertSame(2,$t->version);
 }
}
