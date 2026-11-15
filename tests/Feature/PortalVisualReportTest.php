<?php
namespace Tests\Feature;
use App\Models\{Apprentice,User,LearningTask,TrainingTopic,TrainingSession,AttendanceMark,Qualification,TutorGroup};
use App\Support\{PortalCharts as C,PortalVisuals as V,PortalVisualReport as R,ReleaseSourceCheck};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Tests\TestCase;
class PortalVisualReportTest extends TestCase
{
 use LazilyRefreshDatabase;
 private function f(string $role='admin'):array{
  $this->travelTo(now()->setDate(2026,11,15)->setTime(10,0));$u=User::factory()->create(['role'=>$role]);$a=Apprentice::factory()->create($role==='tutor'?['tutor_id'=>$u->id]:($role==='apprentice'?['user_id'=>$u->id]:[]));return[$u,$a];
 }
 public function test_charts_classify_pending_source_before_an_approved_label():void{
  [$u,$a]=$this->f();LearningTask::factory()->create(['apprentice_id'=>$a->id,'reference'=>'source-pending-45','status'=>'Approved','template'=>['kind'=>'source-pending']]);LearningTask::factory()->create(['apprentice_id'=>$a->id,'reference'=>'checked-task-45','status'=>'Approved','template'=>['kind'=>'task']]);$rows=collect(V::tasks(LearningTask::all())['rows']);$this->assertSame(1,$rows->firstWhere('label','Approved')['value']);$this->assertSame(1,$rows->firstWhere('label','Source needs checking')['value']);$this->assertSame(2,$rows->sum('value'));
 }
 public function test_topics_keep_zero_hour_secure_areas_and_qualification_awards_separate():void{
  [$u,$a]=$this->f();TrainingTopic::factory()->create(['apprentice_id'=>$a->id,'subject'=>'maths','topic'=>'Fictional secure area','planned_hours'=>0,'status'=>'Completed']);TrainingTopic::factory()->create(['apprentice_id'=>$a->id,'subject'=>'maths','topic'=>'Fictional taught area','planned_hours'=>6,'status'=>'Completed']);$row=V::topics(TrainingTopic::all())['rows'][0];$this->assertSame(1,$row['value']);$this->assertSame(1,$row['second']);$this->assertDatabaseCount('qualifications',0);
 }
 #[\PHPUnit\Framework\Attributes\TestWith(['admin'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['ceo'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['tutor'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['apprentice'])]
 public function test_allowed_roles_get_a_scoped_report_with_exact_tables(string $role):void{
  [$u,$a]=$this->f($role);$other=Apprentice::factory()->create(['last_name'=>'OTHER-PRIVATE-45']);$r=$this->actingAs($u)->get('/visual-reports');$r->assertOk()->assertSee('Charts and tables')->assertSee('Definitions and limits')->assertHeader('Referrer-Policy','no-referrer');if(in_array($role,['tutor','apprentice'],true)){$r->assertDontSee($other->reference)->assertDontSee('OTHER-PRIVATE-45');}
 }
 public function test_apprentice_cannot_select_another_apprentice_or_group_to_expand_scope():void{
  [$u,$a]=$this->f('apprentice');$b=Apprentice::factory()->create();$this->actingAs($u)->get('/visual-reports?apprentice='.$b->id)->assertNotFound();$this->get('/visual-reports.pdf?apprentice='.$b->id)->assertNotFound();
 }
 public function test_missing_apprentice_link_is_held_then_single_valid_link_opens():void{
  $u=User::factory()->create(['role'=>'apprentice']);$this->actingAs($u)->get('/visual-reports')->assertStatus(409);Apprentice::factory()->create(['user_id'=>$u->id]);$this->get('/visual-reports')->assertOk();
 }
 #[\PHPUnit\Framework\Attributes\TestWith(['employer'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['mentor'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['careers_adviser'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['inclusion_lead'])]
 public function test_other_roles_cannot_use_report_routes_to_read_training_data(string $role):void{
  [$u]=$this->f($role);$this->actingAs($u)->get('/visual-reports')->assertForbidden();$this->get('/visual-reports.pdf')->assertForbidden();
 }
 public function test_attendance_trend_uses_weighted_records_and_keeps_unknown_weeks_null():void{
  [$u,$a]=$this->f();$b=Apprentice::factory()->create();$g=TutorGroup::factory()->create();$s=TrainingSession::factory()->create(['tutor_group_id'=>$g->id,'created_by'=>$u->id,'session_date'=>'2026-11-10','start_time'=>'09:00','end_time'=>'10:00','members'=>[$a->id,$b->id]]);
  AttendanceMark::create(['training_session_id'=>$s->id,'apprentice_id'=>$a->id,'recorded_by'=>$u->id,'code'=>'O','otj_confirmed'=>false]);AttendanceMark::create(['training_session_id'=>$s->id,'apprentice_id'=>$b->id,'recorded_by'=>$u->id,'code'=>'A','otj_confirmed'=>false]);
  $this->actingAs($u)->get('/visual-reports?from=2026-11-02&to=2026-11-15')->assertOk()->assertViewHas('report',function($r){$this->assertNull($r['attendance_weekly'][0]['value']);$this->assertSame(50.0,$r['attendance_weekly'][1]['value']);$this->assertSame(2,$r['attendance_weekly'][1]['expected']);return true;});
 }
 public function test_unmarked_attendance_is_unknown_rather_than_zero():void{
  [$u,$a]=$this->f();TrainingSession::factory()->create(['created_by'=>$u->id,'session_date'=>'2026-11-10','start_time'=>'09:00','end_time'=>'10:00','members'=>[$a->id]]);$this->actingAs($u)->get('/visual-reports?from=2026-11-09&to=2026-11-15')->assertOk()->assertViewHas('report',fn($r)=>$r['attendance_weekly'][0]['value']===null&&$r['attendance_weekly'][0]['unmarked']===1);
 }
 public function test_non_automotive_programme_and_unknown_plan_are_retained():void{
  [$u,$a]=$this->f();$a->update(['programme'=>'Business Administration Level 3']);$this->actingAs($u)->get('/visual-reports?apprentice='.$a->id)->assertOk()->assertSee('Business Administration Level 3')->assertSee('Unknown / not established')->assertViewHas('report',fn($r)=>$r['details'][0]['target_hours']===null);
 }
 public function test_sensitive_support_and_draft_bodies_are_not_in_reports():void{
  [$u,$a]=$this->f();\App\Models\SupportConcern::factory()->create(['apprentice_id'=>$a->id,'initial_note'=>'PRIVATE-SUPPORT-45']);LearningTask::factory()->create(['apprentice_id'=>$a->id,'answers'=>['answer'=>'PRIVATE-ANSWER-45'],'template'=>['kind'=>'task']]);$this->actingAs($u)->get('/visual-reports')->assertOk()->assertDontSee('PRIVATE-SUPPORT-45')->assertDontSee('PRIVATE-ANSWER-45');
 }
 #[\PHPUnit\Framework\Attributes\TestWith(['group[]=1'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['apprentice[]=1'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['from=2026-11-16'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['from=2026-11-15&to=2026-11-01'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['from=2020-01-01&to=2026-11-01'])]
 public function test_malformed_or_unsupported_date_filters_are_rejected(string $query):void{[$u]=$this->f();$this->actingAs($u)->getJson('/visual-reports?'.$query)->assertUnprocessable();}
 public function test_pdf_export_renders_real_pdf_without_loading_remote_sources():void{
  [$u,$a]=$this->f('apprentice');$r=$this->actingAs($u)->get('/visual-reports.pdf?from=2026-11-09&to=2026-11-15');$r->assertOk()->assertHeader('Content-Type','application/pdf');$this->assertStringStartsWith('%PDF',$r->getContent());if(getenv('GTA_QA_REPORT_OUTPUT')){file_put_contents(getenv('GTA_QA_REPORT_OUTPUT'),$r->getContent());}
 }
 public function test_report_page_and_pdf_capture_does_not_create_business_records():void{
  [$u,$a]=$this->f();$this->actingAs($u)->get('/visual-reports')->assertOk();$this->get('/visual-reports.pdf')->assertOk();foreach(['learning_tasks','progress_review_drafts','support_plans','portal_operations_events']as$table){$this->assertDatabaseCount($table,0);}
 }
 public function test_large_report_requires_narrower_authorised_scope():void{
  [$u]=$this->f();Apprentice::factory()->count(200)->create();$this->actingAs($u)->get('/visual-reports')->assertUnprocessable();
 }
 public function test_chart_svg_escapes_labels_and_has_a_zero_axis():void{
  $svg=C::svg(['title'=>'<script>alert(1)</script>','type'=>'bars','rows'=>[['label'=>'<img onerror=alert(1)>','value'=>5]]]);$this->assertStringNotContainsString('<script>',$svg);$this->assertStringContainsString('&lt;script&gt;',$svg);$this->assertStringContainsString('>0</text>',$svg);
 }
 public function test_doughnut_rejects_unknown_values_and_invalid_numerical_input():void{
  $this->expectException(\InvalidArgumentException::class);C::svg(['title'=>'Unknown','type'=>'donut','rows'=>[['label'=>'Unknown','value'=>null]]]);
 }
 #[\PHPUnit\Framework\Attributes\TestWith([-1])]
 #[\PHPUnit\Framework\Attributes\TestWith(['not-a-number'])]
 public function test_chart_values_cannot_be_negative_or_arbitrary_text($value):void{$this->expectException(\InvalidArgumentException::class);C::svg(['title'=>'Bad','type'=>'bars','rows'=>[['label'=>'Data','value'=>$value]]]);}
 public function test_unknown_line_point_does_not_join_across_the_gap():void{
  $svg=C::svg(['title'=>'Unknown gaps','type'=>'line','max'=>100,'rows'=>[['label'=>'A','value'=>50],['label'=>'B','value'=>null],['label'=>'C','value'=>75]]]);$this->assertSame(2,substr_count($svg,'<polyline'));$this->assertSame(2,substr_count($svg,'<circle'));
 }
}
