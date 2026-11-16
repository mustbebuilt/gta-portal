<?php
namespace Tests\Feature;
use App\Models\{User,Apprentice,LearningTask};
use App\Support\{PortalCharts as C,Release50Check as R};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;
class PortalDesign50Test extends TestCase
{
 use LazilyRefreshDatabase;
 public function test_login_has_one_brand_theme_control_and_actual_fields():void{
  $r=$this->get('/login');$r->assertOk()->assertSee('data-design-release="50"',false)->assertSee('data-signin-theme',false)->assertSee('name="email"',false)->assertSee('name="password"',false)->assertSee('name="_token"',false)->assertSee('Forgotten your password?')->assertSee('Privacy notice')->assertSee('signin-theme50.js',false)->assertDontSee('class="eyebrow">GTA Portal',false);$this->assertSame(1,substr_count($r->getContent(),'id="signin-theme-toggle"'));
 }
 public function test_vector_brand_has_white_infill_and_dark_letters_in_both_modes():void{
  $s=file_get_contents(public_path('assets/gta-mark.svg'));$this->assertStringContainsString('fill="#ffffff"',$s);$this->assertStringContainsString('<g fill="#111111">',$s);$this->assertStringNotContainsString('<script',$s);
 }
 #[TestWith(['admin'])] #[TestWith(['tutor'])] #[TestWith(['apprentice'])]
 public function test_cards_and_ring_share_source_checked_scoped_counts(string $role):void{
  $u=User::factory()->create(['role'=>$role]);$a=Apprentice::factory()->create($role==='tutor'?['tutor_id'=>$u->id]:($role==='apprentice'?['user_id'=>$u->id]:[]));$b=Apprentice::factory()->create();foreach([['Known50','task'],['Missing source50','source-pending']]as[$title,$kind])LearningTask::factory()->create(['apprentice_id'=>$a->id,'reference'=>$title,'title'=>$title,'status'=>'Approved','template'=>['kind'=>$kind]]);LearningTask::factory()->create(['apprentice_id'=>$b->id,'title'=>'PRIVATE-OTHER50','status'=>'Referred']);
  $this->actingAs($u)->get('/dashboard?apprentice='.$a->id)->assertOk()->assertSee('data-task-summary',false)->assertSee('Source needs checking: 1')->assertSee('1 of 2 assigned tasks approved.')->assertSee('50%')->assertSee('Missing source50')->assertDontSee('PRIVATE-OTHER50');$this->get('/visual-reports?apprentice='.$a->id)->assertOk()->assertSee('data-task-summary',false)->assertSee('Source needs checking: 1')->assertDontSee('PRIVATE-OTHER50');
 }
 public function test_attention_uses_real_links_without_exposing_answers_or_other_scope():void{
  $u=User::factory()->create(['role'=>'tutor']);$a=Apprentice::factory()->create(['tutor_id'=>$u->id]);$t=LearningTask::factory()->create(['apprentice_id'=>$a->id,'title'=>'Feedback50','status'=>'Referred','answers'=>['private'=>'PRIVATE-ANSWER50']]);$b=Apprentice::factory()->create();LearningTask::factory()->create(['apprentice_id'=>$b->id,'title'=>'PRIVATE-HIDDEN50','status'=>'Awaiting marking']);$this->actingAs($u)->get('/dashboard')->assertOk()->assertSee(route('tasks.show',$t),false)->assertSee('Feedback50')->assertDontSee('PRIVATE-ANSWER50')->assertDontSee('PRIVATE-HIDDEN50');$this->get('/tasks/'.$t->id)->assertOk();
 }
 public function test_attention_preview_is_bounded_with_truthful_total():void{
  $u=User::factory()->create(['role'=>'tutor']);$a=Apprentice::factory()->create(['tutor_id'=>$u->id]);LearningTask::factory()->count(15)->sequence(fn($seq)=>['reference'=>'many50-'.$seq->index])->create(['apprentice_id'=>$a->id,'status'=>'Referred','template'=>['kind'=>'task']]);$this->actingAs($u)->get('/dashboard')->assertOk()->assertSee('Showing 12 of 15.');
 }
 public function test_empty_scope_does_not_invent_a_success_rate():void{
  $this->actingAs(User::factory()->create(['role'=>'tutor']))->get('/dashboard')->assertOk()->assertSee('No matching tasks in this view')->assertSee('No positive recorded values in this scope')->assertSee('No completion percentage available.')->assertDontSee('100%');
 }
 public function test_pending_source_referred_label_is_held_out_of_referred_count():void{
  $u=User::factory()->create(['role'=>'admin']);$a=Apprentice::factory()->create();LearningTask::factory()->create(['apprentice_id'=>$a->id,'status'=>'Referred','template'=>['kind'=>'source-pending']]);$this->actingAs($u)->get('/dashboard')->assertOk()->assertSee('Source needs checking: 1')->assertSee('data-status="referred"><span>Referred</span><strong>0</strong>',false);
 }
 public function test_attention_orders_recorded_dates_before_missing_dates():void{
  $u=User::factory()->create(['role'=>'admin']);$a=Apprentice::factory()->create();foreach([['No date50',null],['Earlier date50','2026-11-01'],['Later date50','2026-11-14']]as[$title,$date])LearningTask::factory()->create(['apprentice_id'=>$a->id,'reference'=>$title,'status'=>'Referred','title'=>$title,'due_date'=>$date,'template'=>['kind'=>'task']]);$html=$this->actingAs($u)->get('/dashboard')->getContent();$start=strpos($html,'<table class="visual-task-list">');$table=substr($html,$start,strpos($html,'</table>',$start)-$start);$this->assertLessThan(strpos($table,'Later date50'),strpos($table,'Earlier date50'));$this->assertLessThan(strpos($table,'No date50'),strpos($table,'Later date50'));
 }
 public function test_exact_tables_and_all_display_controls_remain_in_server_html():void{
  $this->actingAs(User::factory()->create(['role'=>'admin']))->get('/visual-reports')->assertOk()->assertSee('data-visual-choice="charts"',false)->assertSee('data-visual-choice="table"',false)->assertSee('data-visual-choice="both"',false)->assertSee('data-visual-table',false)->assertSee('Print and PDF reports include charts and exact tables.');
 }
 #[TestWith([0])] #[TestWith([-1])] #[TestWith(['bogus'])] #[TestWith([INF])] #[TestWith([25])]
 public function test_line_range_cannot_divide_by_zero_or_clip_known_points($max):void{
  $this->expectException(\InvalidArgumentException::class);C::svg(['title'=>'Range','type'=>'line','max'=>$max,'rows'=>[['label'=>'Known','value'=>50]]]);
 }
 public function test_grid_values_zero_and_unknown_are_explicit():void{
  $s=C::svg(['title'=>'Hours','type'=>'bars','rows'=>[['label'=>'Known','value'=>6.5],['label'=>'Missing','value'=>null],['label'=>'Zero','value'=>0]]]);foreach(['class="chart-grid"','>6.5</text>','>Unknown</text>','>0</text>','class="chart-axis"']as$v)$this->assertStringContainsString($v,$s);
 }
 public function test_grouped_series_are_distinct_and_unknown_is_not_zero():void{
  $s=C::svg(['title'=>'Topics','type'=>'grouped','rows'=>[['label'=>'Maths','value'=>2,'second'=>null],['label'=>'English','value'=>0,'second'=>0]]]);foreach(['fill="#777b81"','fill="#12833d"','>Unknown</text>']as$v)$this->assertStringContainsString($v,$s);
 }
 public function test_grouping_tail_does_not_lose_exact_table_rows():void{
  $chart=['key'=>'many','title'=>'Many','type'=>'bars','unit'=>'apprentices','note'=>'Current scope','rows'=>array_map(fn($i)=>['label'=>'Programme'.$i,'value'=>$i],range(1,18))];$s=view('visuals.panel',compact('chart'))->render();$this->assertStringContainsString('Further entries (see table)',$s);$this->assertStringContainsString('Programme18',$s);$this->assertSame(19,substr_count($s,'<tr>'));
 }
 public function test_calculated_contrast_is_separate_from_target_browser_acceptance():void{
  $r=R::report();$this->assertNotSame('needs_attention',$r['status']);$this->assertCount(20,$r['theme_contrast']);foreach($r['theme_contrast']as$v)$this->assertGreaterThanOrEqual(4.5,$v);$this->assertFalse($r['actual_target_acceptance']);$this->assertFalse($r['writes_performed']);$this->assertSame(0,$r['new_migrations']);$this->assertSame(21.0,R::contrast('#ffffff','#000000'));
 }
 public function test_release_command_does_not_write_business_records():void{
  $this->artisan('gta:release-check50')->assertExitCode(0);foreach(['learning_tasks','portal_operations_events','centre_manager_grants']as$t)$this->assertDatabaseCount($t,0);
 }
 public function test_empty_bar_and_line_scopes_render_without_maximum_errors():void{
  foreach(['bars','line','grouped','donut']as$type)$this->assertStringContainsString('<svg',C::svg(['title'=>'Empty','type'=>$type,'rows'=>[]]));
 }
 public function test_actual_pdf_export_still_works():void{
  $u=User::factory()->create(['role'=>'apprentice']);$a=Apprentice::factory()->create(['user_id'=>$u->id]);LearningTask::factory()->create(['apprentice_id'=>$a->id,'status'=>'Approved','template'=>['kind'=>'task']]);$r=$this->actingAs($u)->get('/visual-reports.pdf');$r->assertOk()->assertHeader('Content-Type','application/pdf');$this->assertStringStartsWith('%PDF',$r->getContent());if(getenv('GTA_QA_DESIGN50_PDF'))file_put_contents(getenv('GTA_QA_DESIGN50_PDF'),$r->getContent());
 }
}
