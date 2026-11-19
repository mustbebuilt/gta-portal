<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use App\Models\{User,Apprentice,Employer,TutorGroup,Programme};
use Illuminate\Support\Str;
class PriorityWorkflows68Test extends TestCase {
 use LazilyRefreshDatabase;
 private function actor(string $role='admin'):User{$u=User::factory()->create();$u->role=$role;$u->save();return $u;}
 private function capture($response,string $name):void{if(getenv('GTA_SETUP68_CAPTURE_DIR'))file_put_contents(getenv('GTA_SETUP68_CAPTURE_DIR').'/'.$name.'.html',$response->getContent());}
 public function test_home_and_apprentices_open_same_setup_without_an_intermediate_screen():void{
  $u=$this->actor();$this->actingAs($u);$home=$this->get(route('dashboard.index'))->assertOk()->assertSee('Start a task')->assertSee('Add apprentice')->assertSee('Add employer')->assertSee('View progress');$this->capture($home,'admin-home');
  $this->get(route('apprentices.index'))->assertOk()->assertSee(route('setup68.create'));$r=$this->post(route('setup68.create'))->assertRedirect();$this->get($r->headers->get('Location'))->assertOk()->assertSee('Apprentice details');$this->assertDatabaseCount('apprentices',0);
 }
 public function test_standalone_employer_is_idempotent_and_duplicate_reference_is_not_recreated():void{
  $u=$this->actor();$this->actingAs($u);$this->get(route('employer68.show'))->assertOk();$data=['token'=>(string)Str::uuid(),'data'=>['reference'=>'E68','name'=>'Synthetic Workshop 68']];$this->post(route('employer68.store'),$data)->assertRedirect()->assertSessionHasNoErrors();$this->post(route('employer68.store'),$data)->assertRedirect()->assertSessionHasNoErrors();$this->assertDatabaseCount('employers',1);$this->get(route('employer68.show',['search'=>'Workshop']))->assertOk()->assertSee('Synthetic Workshop 68');$data['token']=(string)Str::uuid();$this->post(route('employer68.store'),$data)->assertSessionHasErrors();$this->assertDatabaseCount('employers',1);
 }
 public function test_employer_name_match_requires_review_and_contacts_are_optional():void{
  $this->actingAs($u=$this->actor());Employer::create(['reference'=>'OLD68','name'=>'Synthetic Workshop']);$data=['token'=>(string)Str::uuid(),'data'=>['reference'=>'NEW68','name'=>'Synthetic Workshop']];$this->post(route('employer68.store'),$data)->assertSessionHasErrors();$data['data']['duplicate_reason']='Separate registered organisation confirmed';$this->post(route('employer68.store'),$data)->assertRedirect()->assertSessionHasNoErrors();$this->assertDatabaseCount('employers',2);
 }
 public function test_tutor_follows_flag_to_progress_then_returns_to_exact_filtered_list():void{
  $tutor=$this->actor('tutor');$other=$this->actor('tutor');$g=TutorGroup::factory()->create(['tutor_id'=>$tutor->id]);$a=Apprentice::factory()->create(['first_name'=>'Priority','last_name'=>'Learner','tutor_id'=>$tutor->id,'tutor_group_id'=>$g->id,'start_date'=>'2026-01-01']);$b=Apprentice::factory()->create(['tutor_id'=>$other->id]);\App\Models\SmartAction::factory()->create(['apprentice_id'=>$a->id,'created_by'=>$tutor->id,'owner'=>'Tutor','title'=>'Synthetic overdue progress follow-up','due_date'=>today()->subDay()]);$this->actingAs($tutor);
  $f=['group'=>$g->id,'q'=>'Priority','metric'=>'overdue','sort'=>'name','direction'=>'desc','page'=>1];$list=$this->get(route('progress66.index',$f))->assertOk()->assertSee('Priority Learner')->assertDontSee($b->reference);$this->capture($list,'tutor-progress-list');$report=$list->viewData('report');$this->assertCount(1,$report['details']);$this->assertTrue($report['actions'][0]['overdue']);$url=$report['actions'][0]['progress_url68'];$detail=$this->get($url)->assertOk()->assertSee('Back to filtered list');$this->capture($detail,'tutor-progress-detail');$back=$detail->viewData('report')['back68'];parse_str(parse_url($back,PHP_URL_QUERY),$query);foreach($f as $k=>$v)$this->assertEquals($v,$query[$k]);$returned=$this->get($back)->assertOk();$this->assertEquals($report['filters'],$returned->viewData('report')['filters']);$this->get(route('progress66.index',['apprentice'=>$b->id]))->assertNotFound();$this->get(route('progress66.index',['tutor'=>$other->id]))->assertForbidden();
 }
 public function test_admin_can_filter_progress_by_authorised_tutor():void{
  $this->actingAs($this->actor());$t=$this->actor('tutor');$other=$this->actor('tutor');$a=Apprentice::factory()->create(['tutor_id'=>$t->id]);$b=Apprentice::factory()->create(['tutor_id'=>$other->id]);$r=$this->get(route('progress66.index',['tutor'=>$t->id]))->assertOk();$this->assertEquals([(string)$a->id],array_column($r->viewData('report')['rows'],'id'));$this->assertArrayHasKey($t->id,$r->viewData('report')['tutors68']);
 }
 public function test_employer_setup_and_drafts_are_not_exposed_to_tutor_or_employer():void{
  foreach(['tutor','employer','mentor'] as $role){$this->actingAs($this->actor($role));$this->get(route('employer68.show'))->assertForbidden();$this->post(route('employer68.store'),['token'=>(string)Str::uuid(),'data'=>['reference'=>'DENY','name'=>'Deny']])->assertForbidden();$this->get(route('setup68.index'))->assertForbidden();}$this->assertDatabaseCount('employers',0);
 }
 public function test_malformed_return_context_cannot_create_an_external_return_url():void{
  $this->actingAs($this->actor());$this->get(route('progress66.index',['origin68'=>'%%%']))->assertStatus(422);$r=$this->get(route('progress66.index',['origin68'=>base64_encode(json_encode(['return_url'=>'https://example.invalid','q'=>'filter']))]))->assertOk();$this->assertStringNotContainsString('example.invalid',$r->viewData('report')['back68']);
 }
 public function test_employer_search_preserves_the_current_saved_proposal_and_prior_steps():void{
  $this->actingAs($u=$this->actor());$d=\App\Support\GuidedSetup68::start($u,$u->auth_version);$d=\App\Support\GuidedSetup68::save($u,$u->auth_version,$d,$d->version,1,['reference'=>'SEARCH68','first_name'=>'Keep','last_name'=>'Details']);$d=\App\Support\GuidedSetup68::save($u,$u->auth_version,$d,$d->version,5,['reference'=>'PENDING68','name'=>'Keep proposal']);$this->post(route('setup68.save',[$d,5]),['version'=>$d->version,'employer_search'=>'Workshop','next'=>5])->assertRedirect()->assertSessionHasNoErrors();$this->assertSame('Keep proposal',$d->fresh()->payload['employer']['name']);$this->assertSame('Keep',$d->fresh()->payload['details']['first_name']);
 }
 public function test_controlled_template_import_requires_authorised_actor_and_writes_dedicated_audit():void{
  $u=$this->actor();$source=GuidedSetup68Test::source();Programme::create(['standard_code'=>'ST0499','version'=>'1.3','name'=>'Synthetic import','level'=>2]);$file=tempnam(sys_get_temp_dir(),'gta68-');file_put_contents($file,json_encode($source));try{$this->artisan('gta:setup68-import-template',['file'=>$file,'--actor'=>$u->id])->assertExitCode(1);$this->artisan('gta:setup68-import-template',['file'=>$file,'--actor'=>$u->id,'--approved-source'=>'SYNTHETIC-APPROVAL'])->assertExitCode(0);$this->assertDatabaseCount('programme_setup_templates68',1);$this->assertDatabaseCount('setup_template_events68',1);$this->assertDatabaseHas('setup_template_events68',['actor_id'=>$u->id,'version'=>1]);}finally{unlink($file);}
 }
}
