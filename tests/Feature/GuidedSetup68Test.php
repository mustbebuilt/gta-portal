<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use App\Models\{Apprentice,ApprenticeSetupDraft68 as Draft,ProgrammeSetupTemplate68 as Template,Programme,Employer,User,TrainingPlanWorkspace};
use App\Support\{GuidedSetup68 as Setup,Rpl68,DeliveryPlan,OtjPosition};
use Illuminate\Support\Facades\DB;
class GuidedSetup68Test extends TestCase {
 use LazilyRefreshDatabase;
 public static function source():array {
  $ksbs=[];for($i=1;$i<=50;$i++)$ksbs[]=['code'=>'K'.$i,'description'=>'Controlled test requirement '.$i,'blocks'=>$i===1?[1,2]:[(int)ceil($i/5)],'allocation'=>[(string)(int)ceil($i/5)=>1]];
  $blocks=[];for($i=1;$i<=12;$i++)$blocks[]=['number'=>$i,'title'=>$i<=10?'Training block '.$i:($i===11?'Gateway':'EPA'),'hours'=>$i<=10?60:0,'start_day'=>($i-1)*63,'end_day'=>$i*63-1];
  return ['name'=>'Synthetic 50-KSB acceptance fixture','standard_code'=>'ST0499','standard_version'=>'1.3','source_ref'=>'Synthetic approved fixture only','ksb_ref'=>'Fixture KSB matrix','qualifications_ref'=>'Fixture qualifications','release_ref'=>'Fixture day release','full_hours'=>600,'ksbs'=>$ksbs,'blocks'=>$blocks];
 }
 private function actor(string $role='admin'):User {$u=User::factory()->create();$u->role=$role;$u->save();return $u;}
 private function template():Template {$p=Programme::firstOrCreate(['standard_code'=>'ST0499','version'=>'1.3','name'=>'Autocare acceptance fixture','level'=>2]);$t=self::source();return Template::where('programme_id',$p->id)->first()??Template::create(['programme_id'=>$p->id,'template'=>$t,'source_hash'=>Rpl68::hash($t),'active'=>true]);}
 private function save(Draft $d,int $step,array $data):Draft {$this->post('/apprentice-setup/'.$d->id.'/steps/'.$step,['version'=>$d->version,'data'=>$data])->assertRedirect()->assertSessionHasNoErrors();return $d->fresh();}
 private function capture(Draft $d,int $step,string $scenario):void {if(!getenv('GTA_SETUP68_CAPTURE_DIR'))return;$response=$this->get('/apprentice-setup/'.$d->id.'/'.$step)->assertOk();file_put_contents(getenv('GTA_SETUP68_CAPTURE_DIR').'/'.$scenario.'-step'.$step.'.html',$response->getContent());}
 private function ready(bool $partial=false, ?Template $template=null, bool $submit=true):array {
  $author=$this->actor();$reviewer=$this->actor();$this->actingAs($author);$t=$template??$this->template();$this->post('/apprentice-setup')->assertRedirect();$d=Draft::where('owner_id',$author->id)->firstOrFail();
  $d=$this->save($d,1,['reference'=>$partial?'DEMO68-B':'DEMO68-A','first_name'=>$partial?'Morgan':'Alex','last_name'=>'Acceptance','email'=>$partial?'morgan@example.invalid':'alex@example.invalid']);
  $this->capture($d,1,$partial?'partial':'none');$this->get('/apprentice-setup/'.$d->id.'/2')->assertOk()->assertSee('Inclusion Markers');$d=$this->save($d,2,['state'=>'pending']);
  $this->capture($d,2,$partial?'partial':'none');$d=$this->save($d,3,['template_id'=>$t->id,'start_date'=>'2026-11-18']);
  $this->capture($d,3,$partial?'partial':'none');$d=$this->save($d,4,$partial?['mode'=>'applies','assessment_ref'=>'ASSESSMENT-68-B','adjustments'=>['K1'=>20,'K2'=>30,'K3'=>40,'K4'=>50],'remaining_confirmed'=>true]:['mode'=>'none']);
  $this->capture($d,4,$partial?'partial':'none');if($partial){$d=$this->save($d,5,['reference'=>'NEW68','name'=>'New Acceptance Workshop','contact_name'=>'Test workplace mentor','contact_email'=>'workshop@example.invalid','use_contact'=>true]);$d=$this->save($d,5,[...$d->payload['employer'],'agreement_ref'=>'EMPLOYER-AGREEMENT-68-B','agreement_verified'=>true]);}
  else {$e=Employer::create(['reference'=>'EXIST68','name'=>'Existing Acceptance Workshop']);$d=$this->save($d,5,['id'=>$e->id]);}
  $this->capture($d,5,$partial?'partial':'none');$this->assertSame([],Setup::review($d)['issues']);$this->get('/apprentice-setup/'.$d->id.'/7')->assertOk()->assertSee('Check the setup');
  if($submit)$this->post('/apprentice-setup/'.$d->id.'/submit',['version'=>$d->version,'confirmed'=>1])->assertRedirect()->assertSessionHasNoErrors();return [$d->fresh(),$author,$reviewer,$t];
 }
 private function complete(bool $partial=false):array {[$d,$author,$reviewer,$t]=$this->ready($partial, submit:false);$this->post('/apprentice-setup/'.$d->id.'/confirm',['version'=>$d->version,'confirmed'=>1])->assertRedirect()->assertSessionHasNoErrors();return [$d->fresh(),$author,$reviewer,$t];}
 public function test_continue_setup_finds_first_missing_item_without_creating_a_second_draft(): void
 {
  $user = $this->actor();
  $this->actingAs($user)->post(route('setup68.create'))->assertRedirect();
  $draft = Draft::where('owner_id', $user->id)->sole();
  $draft = $this->save($draft, 1, ['reference' => 'DRAFT-P93', 'first_name' => 'Alex', 'last_name' => 'Fiction']);
  $this->get(route('apprentices.index'))->assertOk()->assertSee('Add apprentice or continue setup');
  $this->get(route('setup68.index'))->assertOk()->assertSee('Continue setup')->assertSee('Alex');
  $this->get(route('setup68.show', $draft))->assertOk()->assertViewHas('step', 2);
  $this->assertSame('DRAFT-P93', $draft->fresh()->payload['details']['reference']);
  $this->assertDatabaseCount('apprentice_setup_drafts68', 1);
  $this->actingAs($this->actor('tutor'))->get(route('setup68.show', $draft))->assertForbidden();
 }
 public function test_programme_picker_names_unavailable_sources_and_preserves_draft_reductions(): void
 {
  $admin = $this->actor();
  $this->actingAs($admin);
  $template = $this->template();
  Programme::firstOrCreate(['standard_code'=>'ST0068','version'=>'1.5','name'=>'Fictional Heavy Vehicle','level'=>3]);
  $draft = Setup::start($admin, $admin->auth_version);
  $programme = route('setup68.show', [$draft, 3]);
  $this->get($programme)->assertOk()->assertSee('ST0499 v1.3')->assertSee('Fictional Heavy Vehicle · ST0068 v1.5 · Approved training template missing');
  $draft = $this->save($draft, 3, ['template_id'=>$template->id, 'start_date'=>'2026-11-18']);
  $this->get($programme)->assertOk()->assertSee('600.00 starting OTJ hours · 50 KSBs · 12 blocks')->assertSee('Training block 1');
  $draft = $this->save($draft, 4, ['mode'=>'applies','assessment_ref'=>'Fictional RPL','adjustments'=>['K1'=>20],'remaining_confirmed'=>true]);
  $template->update(['active'=>false]);
  $this->get($programme)->assertOk()->assertSee('Training template inactive or does not match the programme');
  $draft = $this->save($draft, 3, ['template_id'=>null,'start_date'=>'2026-11-18']);
  $this->assertSame($template->id, $draft->payload['programme']['template_id']);
  $this->assertSame(['K1'=>20], $draft->payload['rpl']['adjustments']);
 }

 public function test_admin_resumes_one_draft_with_saved_rpl_assignments_and_planned_dates(): void
 {
  [$draft, $user, , $template] = $this->ready(true, submit:false);
  $tutor = $this->actor('tutor');
  $group = \App\Models\TutorGroup::factory()->create(['name'=>'Fictional Tuesday group','programme_id'=>$template->programme_id,'tutor_id'=>$tutor->id]);
  $priorLearning = $draft->payload['rpl'];
  $savedEmployer = $draft->payload['employer'];
  $draft = $this->save($draft, 3, [...$draft->payload['programme'], 'template_id'=>(string)$template->id,
   'tutor_id'=>$tutor->id, 'tutor_group_id'=>$group->id]);
  $this->assertSame($priorLearning, $draft->payload['rpl']);
  $this->assertSame($savedEmployer, $draft->payload['employer']);
  $rpl = route('setup68.show',[$draft,4]);
  $this->get($rpl)->assertOk()->assertSee('Hours saved to this draft')->assertSee('583.20 hours');
  $this->post(route('setup68.save',[$draft,4]), [
   'version'=>$draft->version,'action'=>'record_rpl','next'=>4,
   'data'=>['mode'=>'applies','assessment_ref'=>$priorLearning['assessment_ref']],
   'reductions'=>$priorLearning['adjustments'],
  ])->assertRedirect($rpl)->assertSessionHas('status','Prior learning saved to this draft. Planned OTJ 583.20 hours.');
  $draft->refresh();
  $this->assertSame($savedEmployer, $draft->payload['employer']);
  $this->assertSame($priorLearning, $draft->payload['rpl']);
  $skills = \App\Support\FunctionalSkillsSetup82::defaults($user);
  $skills['maths'] = [...$skills['maths'],'state'=>'recorded','initial_assessment'=>'Fictional initial','diagnostic'=>'Fictional diagnostic'];
  $draft = $this->save($draft, 6, $skills);
  $final = route('setup68.show',[$draft,7]);
  $this->get(route('setup68.index'))->assertOk()->assertSee('Continue setup');
  $this->get(route('setup68.show',$draft))->assertOk()->assertViewHas('step',7);
  $this->get($final)->assertOk()->assertSee('Fictional Tuesday group')->assertSee($tutor->name)
   ->assertSee('Maths · Assessments recorded')->assertSee('English · Follow-up planned')->assertSee('New Acceptance Workshop')
   ->assertSee('Save dates to draft')->assertSee(route('setup68.show',[$draft,3]).'#group');
  $dates = Setup::review($draft)['dates69']['selected'];
  $dates['programme_end'] = (new \DateTimeImmutable($dates['programme_end']))->modify('+2 days')->format('Y-m-d');
  $confirm = route('setup68.confirm',$draft);
  $this->from($final)->post($confirm,[...$dates,'version'=>$draft->version,'intent'=>'save_dates','confirmed'=>1])
   ->assertRedirect($final)->assertSessionHas('status','Planned dates saved to this draft.');
  $this->assertDatabaseCount('apprentices',0);
  $this->assertDatabaseCount('apprentice_setup_drafts68',1);
  $draft->refresh();
  $this->get($final)->assertOk()->assertSee($dates['programme_end']);
  $this->post($confirm,['version'=>$draft->version,'intent'=>'confirm','confirmed'=>1])->assertSessionHasNoErrors();
  $apprentice = Apprentice::findOrFail($draft->fresh()->apprentice_id);
  $this->assertSame($group->id, $apprentice->tutor_group_id);
  $this->assertSame($dates['programme_end'], $apprentice->planned_end_date->toDateString());
  $this->assertEquals(583.20, OtjPosition::forApprentice($apprentice)['summary']['target_hours']);
 }

 public function test_two_complete_http_setups_feed_the_real_approved_plan_otj_and_training_source():void {
  foreach([false,true] as $partial){[$d,$author,$reviewer,$t]=$this->complete($partial);$a=Apprentice::findOrFail($d->apprentice_id);$expected=$partial?583.2:600.0;
   $this->assertEquals($expected,DeliveryPlan::approved($a)['details']['target_hours']);$this->assertEquals($expected,OtjPosition::forApprentice($a)['summary']['target_hours']);$this->assertEquals($expected,\App\Support\TrainingPlanSource::position(TrainingPlanWorkspace::where('apprentice_id',$a->id)->first())['remaining_hours']);
   $this->assertEquals($expected,array_sum(array_column(DeliveryPlan::approved($a)['blocks'],'hours')));$this->assertDatabaseCount('apprentices',$partial?2:1);$this->get('/apprentice-setup/'.$d->id.'/7')->assertOk()->assertSee('Setup complete')->assertSee('Apprentice login and password help')->assertSee(route('apprentice-onboarding.show',$a));$this->capture($d,6,$partial?'partial':'none');
   $this->get('/apprentices/'.$a->id)->assertOk()->assertSee($a->reference);
   $this->get('/apprentices/'.$a->id.'/otj-ledger')->assertOk()->assertSee(number_format($expected,2));
   $this->get('/apprentices/'.$a->id.'/training-plan')->assertOk()->assertSee(number_format($expected,2));
   if(getenv('GTA_SETUP68_CAPTURE_DIR')){file_put_contents(getenv('GTA_SETUP68_CAPTURE_DIR').'/'.($partial?'partial':'none').'-result.json',json_encode(['scenario'=>$partial?'Partial RPL / new employer':'No relevant prior learning / existing employer','apprentice_reference'=>$a->reference,'programme'=>$a->programmeLabel(),'employer'=>$a->employerLabel(),'calculation'=>Setup::review($d)['calculation'],'approved_plan'=>DeliveryPlan::approved($a),'otj_dashboard_target'=>OtjPosition::forApprentice($a)['summary']['target_hours'],'current_apprentice_count'=>Apprentice::count(),'current_employer_count'=>Employer::count(),'state'=>$d->state,'unique_adjusted_ksbs'=>count($d->payload['rpl']['adjustments']),'remaining_ksbs_confirmed'=>$d->payload['rpl']['remaining_confirmed']],JSON_PRETTY_PRINT));}

  }
 }
 public function test_four_of_fifty_is_entered_once_even_when_ksb_has_two_teaching_blocks():void {$t=self::source();$c=Rpl68::calculate($t,['mode'=>'applies','adjustments'=>['K1'=>20,'K2'=>30,'K3'=>40,'K4'=>50]]);$this->assertSame(4,$c['adjusted_count']);$this->assertSame(46,$c['remaining_count']);$this->assertEquals(16.8,$c['removed_hours']);$this->assertEquals(583.2,$c['revised_hours']);$this->assertEquals(16.8,$c['blocks'][0]['removed_hours']);$this->assertEquals(0,$c['blocks'][1]['removed_hours']);}
 public function test_existing_autocare_links_allocate_saved_reductions_when_a_draft_reopens(): void
 {
  $template = $this->template();
  $source = json_decode(file_get_contents(resource_path('data/setup68-templates/ST0499_v1.3_autocare.json')), true, 512, JSON_THROW_ON_ERROR);
  $template->update(['template' => $source, 'source_hash' => Rpl68::templateHash($source)]);
  $actor = $this->actor();
  $this->actingAs($actor);
  $draft = Setup::start($actor, $actor->auth_version);
  $draft = $this->save($draft, 3, ['template_id' => $template->id, 'start_date' => '2026-11-18']);
  $adjustments = ['K1' => 70, 'K12' => 80, 'K15' => 80];
  $rpl = ['mode' => 'applies', 'assessment_ref' => 'AUT-RPL-TEST', 'adjustments' => $adjustments, 'remaining_confirmed' => true];
  $draft = $this->save($draft, 4, $rpl);
  $url = route('setup68.show', [$draft, 7]);
  $response = $this->get($url)->assertOk()->assertSee('589.52 hours')->assertDontSee('Pending allocation');
  $calculation = $response->viewData('review')['calculation'];
  $this->assertSame($adjustments, $draft->fresh()->payload['rpl']['adjustments']);
  $this->assertSame([], $calculation['unresolved']);
  $this->assertEqualsWithDelta(611.5 / 64 * 0.7 / 9 + 611.5 / 64 * 0.8 / 10, $calculation['blocks'][0]['removed_hours'], 0.0005);
  $this->assertEquals(589.52, round(array_sum(array_column(Rpl68::reviewBlocks($calculation), 'hours')), 2));
  $this->assertEquals(0, $calculation['blocks'][10]['removed_hours']);
  $this->assertEquals(0, $calculation['blocks'][11]['removed_hours']);

  $rpl['adjustments']['K1'] = 80;
  $draft = $this->save($draft, 4, $rpl);
  $updated = $this->get($url)->assertOk()->assertSee('588.57 hours')->viewData('review')['calculation'];
  $draft = $this->save($draft, 4, $rpl);
  $this->assertSame($updated, $this->get($url)->assertOk()->viewData('review')['calculation']);

  $this->save($draft, 4, ['mode' => 'none']);
  $none = $this->get($url)->assertOk()->assertSee('611.50 hours')->viewData('review')['calculation'];
  $this->assertEquals(array_column($none['blocks'], 'original_hours'), array_column($none['blocks'], 'hours'));
 }
 public function test_invalid_percentage_rejected_server_side():void {$this->expectException(\Illuminate\Validation\ValidationException::class);Rpl68::calculate(self::source(),['mode'=>'applies','adjustments'=>['K1'=>25]]);}
 public function test_duplicate_ksb_catalogue_rejected():void {$t=self::source();$t['ksbs'][]=$t['ksbs'][0];$this->expectException(\Illuminate\Validation\ValidationException::class);Rpl68::validateTemplate($t);}
 public function test_author_can_confirm_setup_already_waiting_for_review():void {
  [$d,$author]=$this->ready();
  $this->assertSame('Ready',$d->state);
  $url='/apprentice-setup/'.$d->id;
  $this->get($url.'/7')->assertOk()->assertSee('Confirm and create apprentice')->assertDontSee('Submit for plan review');
  $this->post($url.'/confirm',['version'=>$d->version,'confirmed'=>1])->assertRedirect()->assertSessionHasNoErrors();
  $d->refresh();
  $this->assertSame('Complete',$d->state);
  $this->assertEquals($author->id,DeliveryPlan::approved(Apprentice::findOrFail($d->apprentice_id))['approved_by']);
  $this->assertDatabaseCount('apprentices',1);
 }
 public function test_draft_resumes_and_edit_preserves_other_steps_without_creating_records():void {[$d,$author]=$this->ready(true);$this->post('/apprentice-setup/'.$d->id.'/reopen',['version'=>$d->version,'reason'=>'Correct spelling'])->assertRedirect();$d=$d->fresh();$d=$this->save($d,1,[...$d->payload['details'],'first_name'=>'Morgan corrected']);$this->get('/apprentice-setup/'.$d->id.'/1')->assertOk()->assertSee('Morgan corrected');$this->assertSame(4,count($d->payload['rpl']['adjustments']));$this->assertDatabaseCount('apprentices',0);$this->assertDatabaseCount('employers',0);}
 public function test_duplicate_confirmation_is_idempotent():void {[$d,$author,$reviewer]=$this->complete(true);$this->post('/apprentice-setup/'.$d->id.'/confirm',['version'=>1,'confirmed'=>1])->assertRedirect();$this->assertDatabaseCount('apprentices',1);$this->assertDatabaseCount('employers',1);$this->assertDatabaseCount('individual_delivery_plans',1);}
 public function test_new_employer_matching_existing_reference_is_blocked():void {[$d,$a]=$this->ready(true);Employer::create(['reference'=>'NEW68','name'=>'Already recorded']);$this->assertNotEmpty(Setup::review($d)['issues']);}
 public function test_exact_individual_email_duplicate_is_blocked():void {[$d]=$this->ready();Apprentice::factory()->create(['email'=>'alex@example.invalid']);$this->assertTrue(collect(Setup::review($d)['issues'])->contains(fn($i)=>$i['step']===1));}
 public function test_marker_pending_does_not_become_none_or_a_journey_event():void {[$d]=$this->complete();$this->assertSame('pending',$d->payload['markers']['state']);$this->assertDatabaseCount('apprentice_markers',0);$this->assertStringNotContainsString('markers',json_encode(\App\Support\ApprenticeRoadmap67::project([['type'=>'plan_confirmed','date'=>'2026-11-18','markers'=>['IM10']]],'2026-11-18')));}
 public function test_unauthorised_role_cannot_read_or_write_setup():void {$u=$this->actor('employer');$this->actingAs($u)->get('/apprentice-setup')->assertForbidden();$this->post('/apprentice-setup')->assertForbidden();}
 public function test_stale_draft_write_is_rejected():void {[$d]=$this->ready();$this->post('/apprentice-setup/'.$d->id.'/reopen',['version'=>$d->version,'reason'=>'Correction'])->assertRedirect();$this->post('/apprentice-setup/'.$d->id.'/steps/1',['version'=>1,'data'=>['first_name'=>'Lost write']])->assertStatus(409);}
 public function test_correction_reuses_same_apprentice_employer_and_rpl_record_with_history():void {[$d,$author,$reviewer]=$this->complete(true);$oldPlan=DeliveryPlan::approved(Apprentice::find($d->apprentice_id));$this->actingAs($author)->post('/apprentice-setup/'.$d->id.'/reopen',['version'=>$d->version,'reason'=>'Approved K1 correction'])->assertRedirect();$d=$d->fresh();$r=$d->payload['rpl'];$r['adjustments']['K1']=10;$r['remaining_confirmed']=true;$d=$this->save($d,4,$r);$this->assertEquals(583.2,DeliveryPlan::approved(Apprentice::find($d->apprentice_id))['details']['target_hours']);$d=$this->save($d,5,[...$d->payload['employer'],'agreement_verified'=>true]);$this->post('/apprentice-setup/'.$d->id.'/submit',['version'=>$d->version,'confirmed'=>1])->assertRedirect()->assertSessionHasNoErrors();$d=$d->fresh();$this->actingAs($reviewer)->post('/apprentice-setup/'.$d->id.'/confirm',['version'=>$d->version,'confirmed'=>1])->assertRedirect()->assertSessionHasNoErrors();$this->assertDatabaseCount('apprentices',1);$this->assertDatabaseCount('employers',1);$this->assertDatabaseCount('training_plan_workspaces',1);$this->assertEquals(584.4,DeliveryPlan::approved(Apprentice::find($d->apprentice_id))['details']['target_hours']);$this->assertStringContainsString('Approved K1 correction',json_encode($d->fresh()->history));}
 public function test_template_revision_invalidates_approved_target_instead_of_silently_recalculating():void {[$d,$a,$r,$t]=$this->complete();$value=$t->template;$value['source_ref']='Revised source';$t->template=$value;$t->source_hash=Rpl68::hash($value);$t->save();$this->assertNull(DeliveryPlan::approved(Apprentice::find($d->apprentice_id)));}
 public function test_missing_remainder_confirmation_prevents_submission():void {[$d,$a,$r,$t]=$this->ready(true);$this->post('/apprentice-setup/'.$d->id.'/reopen',['version'=>$d->version,'reason'=>'Continue assessment'])->assertRedirect();$d=$d->fresh();$d=$this->save($d,4,[...$d->payload['rpl'],'remaining_confirmed'=>false]);$this->post('/apprentice-setup/'.$d->id.'/submit',['version'=>$d->version,'confirmed'=>1])->assertSessionHasErrors();$this->assertDatabaseCount('apprentices',0);}
 public function test_preview_bulk_changes_has_no_mutation_then_confirm_applies_once():void {[$d]=$this->ready(true);$this->post('/apprentice-setup/'.$d->id.'/reopen',['version'=>$d->version,'reason'=>'Batch change'])->assertRedirect();$d=$d->fresh();$data=['version'=>$d->version,'action'=>'batch_preview','codes'=>['K1','K2'],'percent'=>20];$response=$this->post('/apprentice-setup/'.$d->id.'/steps/4',$data)->assertOk()->assertSee('Review these changes');$this->assertSame($d->version,$d->fresh()->version);$binding=Rpl68::hash([$d->version,['K1','K2'],20,Setup::template($d->payload)->source_hash]);$this->post('/apprentice-setup/'.$d->id.'/steps/4',[...$data,'action'=>'batch_apply','binding'=>$binding])->assertRedirect()->assertSessionHasNoErrors();$this->assertSame(20,$d->fresh()->payload['rpl']['adjustments']['K2']);$this->assertFalse($d->fresh()->payload['rpl']['remaining_confirmed']);}
 public function test_ksb_type_filters_compose_without_changing_the_draft(): void
 {
  $actor = $this->actor();
  $this->actingAs($actor);
  $template = $this->template();
  $matrix = $template->template;
  $matrix['ksbs'][4]['code'] = 'S1';
  $matrix['ksbs'][5]['code'] = 'B1';
  $template->update(['template' => $matrix, 'source_hash' => Rpl68::hash($matrix)]);

  $draft = Setup::start($actor, $actor->auth_version);
  $draft = $this->save($draft, 3, ['template_id' => $template->id, 'start_date' => '2026-11-18']);
  $show = route('setup68.show', [$draft, 4]);
  $filters = ['ksb_type' => 'S', 'search' => 'CONTROLLED', 'block' => 1];
  $query = http_build_query($filters);
  $codes = fn ($response) => array_column($response->viewData('ksbs'), 'code');

  $this->assertCount(50, $codes($this->get($show)->assertOk()->assertSee('Choose a KSB')));
  $this->assertContains('K1', $codes($this->get($show.'?ksb_type=K')->assertOk()));
  $this->assertSame(['B1'], $codes($this->get($show.'?ksb_type=B')->assertOk()));
  $this->assertCount(50, $codes($this->get($show.'?ksb_type%5B%5D=S')->assertOk()));
  $this->assertSame(['S1'], $codes($this->get($show.'?'.$query)->assertOk()));
  $this->assertSame([], $codes($this->get($show.'?'.$query.'&adjusted=1')->assertOk()));
  $this->assertSame($draft->version, $draft->fresh()->version);

  $save = route('setup68.save', [$draft, 4]).'?'.$query;
  $data = ['version' => $draft->version, 'codes' => ['S1'], 'percent' => 20];
  $preview = $this->post($save, [...$data, 'action' => 'batch_preview'])->assertOk();
  $this->assertSame(['S1'], $codes($preview));
  $this->assertSame($draft->version, $draft->fresh()->version);
  $binding = $preview->viewData('batch')['binding'];
  $this->post($save, [...$data, 'action' => 'batch_apply', 'binding' => $binding])
   ->assertRedirect($show.'?'.$query)->assertSessionHasNoErrors();
  $this->assertSame(['S1' => 20], $draft->fresh()->payload['rpl']['adjustments']);
  $this->assertSame(['S1'], $codes($this->get($show.'?'.$query.'&adjusted=1')->assertOk()));
 }
 public function test_simplified_rpl_saves_the_reference_and_reductions_in_one_step(): void
 {
  $actor = $this->actor();
  $this->actingAs($actor);
  $template = $this->template();
  $matrix = $template->template;
  $matrix['ksbs'][4]['code'] = 'S1';
  $matrix['ksbs'][5]['code'] = 'B1';
  $template->update(['template' => $matrix, 'source_hash' => Rpl68::hash($matrix)]);
  $draft = Setup::start($actor, $actor->auth_version);
  $draft = $this->save($draft, 3, ['template_id' => $template->id, 'start_date' => '2026-11-18']);
  $show = route('setup68.show', [$draft, 4]);
  $save = route('setup68.save', [$draft, 4]);
  $this->get($show)->assertOk()
   ->assertSee('Prior learning decision')->assertSee('<select id="rpl-mode"', false)
   ->assertSee('No relevant prior learning')->assertSee('Apply assessed prior learning')
   ->assertSee('<select id="rpl-ksb">', false)
   ->assertSee('<optgroup label="Skills">', false)->assertSee('<optgroup label="Behaviours">', false)
   ->assertSee('Save and continue')
   ->assertSee('<details id="rpl-adjustments"', false)
   ->assertSee('assets/setup68.js?v=105')
   ->assertDontSee('Saved training-hours preview')
   ->assertSee('Reduction for S1')->assertSee('Reduction for B1')
   ->assertDontSee('Save assessment reference')->assertDontSee('Preview selected changes')
   ->assertDontSee('Save agreed dates');
  $input = ['version' => $draft->version, 'next' => 5, 'action' => 'record_rpl',
   'data' => ['mode' => 'applies', 'assessment_ref' => 'CHECKED-REFERENCE']];
  $this->from($show)->post($save, $input + ['reductions' => ['K1' => '0']])->assertSessionHasErrors('setup');
  $this->post($save, $input + ['reductions' => ['K1' => '15']])->assertSessionHasErrors('reductions.K1');
  $this->post($save, $input + ['reductions' => ['K999' => '0']])->assertSessionHasErrors('setup');
  $this->post($save, $input + ['reductions' => ['K1' => '20', 'S1' => '30', 'B1' => '50']])
   ->assertRedirect(route('setup68.show', [$draft, 5]))->assertSessionHasNoErrors();
  $saved = $draft->fresh();
  $this->assertSame('CHECKED-REFERENCE', $saved->payload['rpl']['assessment_ref']);
  $this->assertTrue($saved->payload['rpl']['remaining_confirmed']);
  $this->assertSame(3, Setup::review($saved)['calculation']['adjusted_count']);
  $this->post($save, $input + ['reductions' => ['K1' => 0]])->assertConflict();
  $this->post($save, [...$input, 'version' => $saved->version, 'data' => ['mode' => 'none']])
   ->assertRedirect(route('setup68.show', [$draft, 5]))->assertSessionHasNoErrors();
  $this->assertSame([], $draft->fresh()->payload['rpl']['adjustments']);
  $this->assertEquals($template->template['full_hours'], Setup::review($draft->fresh())['calculation']['revised_hours']);
 }
 public function test_no_prior_learning_one_click_moves_to_employer():void {$u=$this->actor();$this->actingAs($u);$t=$this->template();$d=Setup::start($u,$u->auth_version);$d=$this->save($d,3,['template_id'=>$t->id,'start_date'=>'2026-11-18']);$this->post('/apprentice-setup/'.$d->id.'/steps/4?next=5',['version'=>$d->version,'action'=>'none'])->assertRedirect('/apprentice-setup/'.$d->id.'/5');$this->assertTrue($d->fresh()->payload['rpl']['remaining_confirmed']);$this->assertSame([], $d->fresh()->payload['rpl']['adjustments']);}
 public function test_employer_search_is_read_only_and_final_check_links_to_the_agreement():void
 {
  $actor=$this->actor();
  $this->actingAs($actor);
  $draft=Setup::start($actor,$actor->auth_version);
  $template=$this->template();
  $draft=$this->save($draft,3,['template_id'=>$template->id,'start_date'=>'2026-11-18']);
  $draft=$this->save($draft,4,['mode'=>'applies','assessment_ref'=>'ASSESSMENT','adjustments'=>['K1'=>20],'remaining_confirmed'=>true]);
  $employer=Employer::create(['reference'=>'SEARCH68','name'=>'Searchable Workshop']);
  $search=route('setup68.show',[$draft,5,'employer_search'=>'search68']);

  $this->get($search)->assertOk()->assertSeeInOrder(['Find an employer','Searchable Workshop','Add a new employer']);
  $this->assertSame($draft->version,$draft->fresh()->version);
  $this->get(route('setup68.show',[$draft,5,'employer_search'=>'No matching workshop']))
   ->assertOk()->assertSee('No active employers match');
  $this->post(route('setup68.save',[$draft,5]),['version'=>$draft->version,'next'=>6,'data'=>['id'=>$employer->id]])
   ->assertRedirect(route('setup68.show',[$draft,5]));
  $draft->refresh();
  $this->get(route('setup68.show',[$draft,5]))->assertOk()->assertSee('Saved employer:')->assertSee('Searchable Workshop');
  $this->get(route('setup68.show',[$draft,7]))->assertOk()
   ->assertSeeInOrder(['Fix before confirming','Planned end dates','Training hours by block'])
   ->assertSee(route('setup68.show',[$draft,5]).'#agreement');
  $this->post(route('setup68.save',[$draft,5]),['version'=>$draft->version,'next'=>6,
   'data'=>['id'=>$employer->id,'agreement_ref'=>'AGREE68','agreement_verified'=>1],
  ])->assertRedirect(route('setup68.show',[$draft,6]))->assertSessionHasNoErrors();
 }

 public function test_employer_change_clears_previous_agreement():void {[$d]=$this->ready(true);$this->post('/apprentice-setup/'.$d->id.'/reopen',['version'=>$d->version,'reason'=>'Employer changed'])->assertRedirect();$d=$d->fresh();$e=Employer::create(['reference'=>'OTHER68','name'=>'Another employer']);$d=$this->save($d,5,['id'=>$e->id,'agreement_ref'=>'OLD-REF','agreement_verified'=>true]);$this->assertFalse($d->payload['employer']['agreement_verified']);$this->assertTrue(collect(Setup::review($d)['issues'])->contains(fn($x)=>$x['step']===5));}
 public function test_guest_and_non_admin_cannot_read_a_private_draft():void {[$d]=$this->ready();$this->actingAs($this->actor('tutor'))->get('/apprentice-setup/'.$d->id.'/2')->assertForbidden();$this->post('/apprentice-setup/'.$d->id.'/steps/2',['version'=>$d->version,'data'=>['state'=>'selected','codes'=>['IM10']]])->assertForbidden();}
 public function test_revoked_auth_version_cannot_start_or_commit():void {$u=$this->actor();$this->actingAs($u);DB::table('users')->where('id',$u->id)->increment('auth_version');$this->post('/apprentice-setup')->assertRedirect()->assertSessionHasErrors();}
 public function test_overallocated_draft_shows_one_message_and_cannot_be_confirmed(): void
 {
  $template = $this->template();
  $source = $template->template;
  $source['ksbs'][0]['allocation'] = [2 => 1];
  $source['blocks'][0]['hours'] = 117;
  $source['blocks'][1]['hours'] = 3;
  $template->update(['template' => $source, 'source_hash' => Rpl68::hash($source)]);
  [$draft] = $this->ready(true, $template, submit: false);
  $rpl = $draft->payload['rpl'];
  $rpl['adjustments']['K1'] = 100;
  $draft = $this->save($draft, 4, $rpl);

  $review = $this->get(route('setup68.show', [$draft, 7]))
   ->assertOk()->assertSee('Provisional OTJ hours')->assertSee('Pending allocation')->viewData('review');
  $this->assertCount(1, array_filter($review['issues'], fn ($issue) => $issue['step'] === 4));
  $this->post(route('setup68.confirm', $draft), ['version' => $draft->version, 'confirmed' => 1])
   ->assertSessionHasErrors();
  $this->assertSame('Draft', $draft->fresh()->state);
  $this->assertDatabaseCount('apprentices', 0);
 }
 public function test_allocation_cannot_remove_more_than_a_blocks_baseline():void {$t=self::source();$t['ksbs'][0]['allocation']=[2=>1];$t['blocks'][1]['hours']=1;$t['blocks'][0]['hours']=119;$c=Rpl68::calculate($t,['mode'=>'applies','adjustments'=>['K1'=>100]]);$this->assertNotEmpty($c['unresolved'][2]);}
 public function test_unknown_ksb_and_string_percentages_are_not_silently_coerced():void {$this->expectException(\Illuminate\Validation\ValidationException::class);Rpl68::calculate(self::source(),['mode'=>'applies','adjustments'=>['K999'=>20]]);}
 public function test_sensitive_draft_payload_and_rpl_history_are_encrypted_at_rest():void {[$d]=$this->ready(true);$raw=DB::table('apprentice_setup_drafts68')->where('id',$d->id)->first();$this->assertStringNotContainsString('Morgan',$raw->payload);$this->assertStringNotContainsString('ASSESSMENT-68-B',$raw->history);$events=$d->history;$rpl=collect($events)->first(fn($e)=>isset($e['rpl_after']));$this->assertSame(20,$rpl['rpl_after']['adjustments']['K1']);}
 public function test_rpl_does_not_create_delivered_hours_or_mark_tasks_achieved():void {[$d]=$this->complete(true);$position=OtjPosition::forApprentice(Apprentice::find($d->apprentice_id));$this->assertSame(0,$position['summary']['recorded_minutes']);$this->assertDatabaseCount('learning_tasks',0);$this->assertDatabaseCount('attendance_marks',0);}
 public function test_profile_change_and_inactive_employer_block_confirmation_transactionally():void {[$d,$author,$reviewer]=$this->ready();$e=Employer::find($d->payload['employer']['id']);$e->active=false;$e->save();$this->actingAs($reviewer)->post('/apprentice-setup/'.$d->id.'/confirm',['version'=>$d->version,'confirmed'=>1])->assertSessionHasErrors();$this->assertDatabaseCount('apprentices',0);}
 public static function dateProgrammes(): array
 {
  return [
   'Autocare' => ['ST0499', '1.3', 600, 50], 'Heavy Vehicle' => ['ST0068', '1.5', 800, 46],
   'Panel' => ['ST0403', '1.3', 760, 44], 'Paint' => ['ST0448', '1.2', 840, 48],
  ];
 }

 #[\PHPUnit\Framework\Attributes\DataProvider('dateProgrammes')]
 public function test_approved_rpl_dates_are_saved_with_the_schedule_and_visible_after_confirmation(
  string $code, string $version, int $hours, int $count
 ): void {
  $matrix = self::source();
  $matrix['standard_code'] = $code;
  $matrix['standard_version'] = $version;
  $matrix['full_hours'] = $hours;
  $matrix['ksbs'] = array_slice($matrix['ksbs'], 0, $count);
  $matrix['ksbs'][4]['code'] = 'S1';
  $matrix['ksbs'][5]['code'] = 'B1';
  foreach (range(0, 9) as $i) $matrix['blocks'][$i]['hours'] = $hours / 10;
  $programme = Programme::firstOrCreate(['standard_code' => $code, 'version' => $version,
   'name' => 'Fictional date test standard', 'level' => $code === 'ST0499' ? 2 : 3]);
  $template = Template::create(['programme_id' => $programme->id, 'template' => $matrix,
   'source_hash' => Rpl68::hash($matrix), 'active' => true]);
  [$draft, $author] = $this->ready(true, $template, submit:false);
  config(['gta_rpl_dates' => ["$code:$version" => [
   'hours_per_training_week' => 6, 'training_weeks_per_year' => 46.4, 'epa_months' => 1,
  ]]]);
  $url = '/apprentice-setup/'.$draft->id;
  $draft = $this->save($draft, 4, [...$draft->payload['rpl'],
   'adjustments' => ['K1' => 20, 'S1' => 30, 'B1' => 50], 'remaining_confirmed' => true]);
  $draft = $this->save($draft, 5, [...$draft->payload['employer'], 'agreement_verified' => true]);
  $review = Setup::review($draft);
  $dates = $review['dates69']['proposed'];
  $this->assertArrayHasKey('practical_end', $dates);
  $start = new \DateTimeImmutable($draft->payload['programme']['start_date']);
  $lines = [];
  for ($i = 0; $i < 10; $i++) {
   $from = $start->modify('+'.($i * 40).' days')->format('Y-m-d');
   $to = $start->modify('+'.($i * 40 + 39).' days')->format('Y-m-d');
   $lines[] = "$from,$to";
  }
  $lines[] = $start->modify('+400 days')->format('Y-m-d').','.$dates['practical_end'];
  $lines[] = (new \DateTimeImmutable($dates['practical_end']))->modify('+1 day')->format('Y-m-d').','.$dates['programme_end'];
  $record = [...$dates, 'source' => 'Fictional approved plan v1', 'agreed_hours' => $review['calculation']['revised_hours'], 'schedule' => implode("\n", $lines)];
  $this->post($url.'/steps/4', ['version' => $draft->version, 'action' => 'save_plan_dates',
   'data' => [...$record, 'programme_end' => '2039-01-01']])->assertRedirect()->assertSessionHasNoErrors();
  $draft->refresh();
  $this->post($url.'/confirm', ['version' => $draft->version, 'confirmed' => 1])->assertSessionHasErrors();
  $this->post($url.'/steps/4', ['version' => $draft->version, 'action' => 'save_plan_dates', 'data' => $record])
   ->assertRedirect()->assertSessionHasNoErrors();
  $draft->refresh();
  $this->get($url.'/7')->assertOk()->assertSee('Fictional approved plan v1')->assertSee('Confirm and create apprentice');
  $this->post($url.'/confirm', ['version' => $draft->version, 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
  $apprentice = Apprentice::findOrFail($draft->fresh()->apprentice_id);
  $this->assertSame($dates['programme_end'], $apprentice->planned_end_date->toDateString());
  $approved = DeliveryPlan::approved($apprentice);
  $this->assertSame($dates['practical_end'], $approved['details']['practical_end_date']);
  $this->assertSame($dates['practical_end'], $approved['blocks'][10]['end']);
  $this->assertSame($dates['programme_end'], $approved['blocks'][11]['end']);
  $this->get('/apprentices/'.$apprentice->id.'/training-plan')->assertOk()->assertSee($dates['practical_end']);
 }
}
