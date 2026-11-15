<?php
namespace Tests\Feature;
use App\Models\{User,StagingAcceptanceRun as Run,StagingAcceptanceCheck as Check,StagingAcceptanceIssue as Issue};
use App\Support\{AcceptanceSource,StagingAcceptance as Acceptance,ReviewedImports};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\{DB,Notification};
use Tests\Support\AuditFault;
use Tests\TestCase;
class StagingAcceptanceTest extends TestCase {
 use LazilyRefreshDatabase;
 private AcceptanceSource $source;
 private function fixture(bool $live=false):array {
  $this->source=new class extends AcceptanceSource {public array $value;public function capture(?string $root=null):array{return $this->value;}};
  $files=['fictional/source.php'=>str_repeat('a',64)];$this->source->value=['status'=>'captured','files'=>$files,'runtime_hash'=>str_repeat('r',64),'fingerprint'=>AcceptanceSource::fingerprint($files,str_repeat('r',64)),'file_count'=>1,'bytes'=>32,'runtime'=>['environment'=>'testing'],'blockers'=>[],'database_accessed'=>false,'writes_performed'=>false,'secrets_collected'=>false,'file_contents_exported'=>false,'deployment_approved'=>false];$this->app->instance(AcceptanceSource::class,$this->source);
  $m=User::factory()->create(['role'=>'admin']);$n=User::factory()->create(['role'=>'ceo']);$details=$this->details($live);$run=Acceptance::create($m,1,$details);return compact('m','n','run','details');
 }
 private function details(bool $live=false):array {return ['title'=>'Fictional scoped staging review','target'=>'Fictional target','reference'=>'FICTIONAL-COMMIT-REFERENCE','programmes'=>'Fictional declared programme/version/cohort','exclusions'=>'Yorkshire recordings paused; real provider excluded unless selected','live_messaging'=>$live];}
 private function observation(string $outcome='Passed'):array{return ['outcome'=>$outcome,'tested_at'=>now()->utc()->format('Y-m-d H:i:s'),'reference'=>'PRIVATE-ACTUAL-TEST-REFERENCE','note'=>'PRIVATE-ACTUAL-TEST-NOTE','confirmed'=>1];}
 private function record(array $f,string $id='accounts',string $outcome='Passed'):Check {Acceptance::recordCheck($f['m'],1,$f['run'],$id,0,$this->observation($outcome));return Check::where('run_id',$f['run']->id)->where('scenario_id',$id)->firstOrFail();}
 private function passed(array $f):void {foreach(Acceptance::scenarios($f['run'])as$id=>$scenario){$c=$this->record($f,$id);Acceptance::reviewCheck($f['n'],1,$c,1,'accept','PRIVATE-INDEPENDENT-NOTE');}}
 private function issueData(string $severity='Major'):array{return ['severity'=>$severity,'scenario_id'=>'accounts','owner_id'=>null,'due_on'=>today()->addDay()->toDateString(),'title'=>'Fictional access finding','detail'=>'PRIVATE-FINDING-DETAIL','reference'=>'PRIVATE-FINDING-REFERENCE','confirmed'=>1];}
 private function issue(array $f,string $severity='Major'):Issue{return Acceptance::saveIssue($f['m'],1,$f['run'],null,0,$this->issueData($severity));}
 private function url(array $f,string $action=''):string{return '/staging-acceptance/'.$f['run']->id.($action?'/'.$action:'');}
 private function changeSource():void {$s=$this->source->value;$s['files']['fictional/source.php']=str_repeat('b',64);$s['fingerprint']=AcceptanceSource::fingerprint($s['files'],$s['runtime_hash']);$this->source->value=$s;}
 public function test_create_captures_encrypted_scope_source_and_no_automatic_pass_or_deployment():void {
  $f=$this->fixture();$p=Acceptance::position($f['run']);$this->assertSame(18,$p['required']);$this->assertSame(0,$p['passed']);$this->assertFalse($p['ready']);$this->assertFalse($p['production_approved']);$raw=DB::table('staging_acceptance_runs')->first();$this->assertStringNotContainsString('Fictional',$raw->details);$this->assertStringNotContainsString('fictional/source.php',$raw->source_snapshot);$this->assertDatabaseCount('staging_acceptance_checks',0);$this->assertArrayNotHasKey('source_snapshot',$f['run']->toArray());
 }
 public function test_live_messaging_scope_adds_required_scenario_without_sending():void {
  Notification::fake();$f=$this->fixture(true);$this->assertCount(19,Acceptance::scenarios($f['run']));$this->assertArrayHasKey('live-messaging',Acceptance::scenarios($f['run']));Notification::assertNothingSent();
 }
 public function test_actual_observation_is_encrypted_and_needs_independent_check():void {
  $f=$this->fixture();$c=$this->record($f);$this->assertSame('Awaiting check',$c->review_state);$this->assertSame(0,Acceptance::position($f['run'])['passed']);$raw=DB::table('staging_acceptance_checks')->first();$this->assertStringNotContainsString('PRIVATE',$raw->details);$this->assertArrayNotHasKey('details',$c->toArray());
 }
 public function test_same_tester_cannot_check_own_observation():void {
  $f=$this->fixture();$c=$this->record($f);$this->actingAs($f['m'])->post('/staging-checks/'.$c->id.'/review',['version'=>1,'decision'=>'accept','note'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('acceptance');$this->assertSame('Awaiting check',$c->fresh()->review_state);
 }
 public function test_independently_accepted_failed_or_blocked_result_never_counts_as_passed():void {
  $f=$this->fixture();foreach(['Failed','Blocked']as$index=>$outcome){$id=$index?'mfa':'accounts';$c=$this->record($f,$id,$outcome);Acceptance::reviewCheck($f['n'],1,$c,1,'accept','Fictional accurate failure');}$this->assertSame(0,Acceptance::position($f['run'])['passed']);$this->assertFalse(Acceptance::position($f['run'])['ready']);
 }
 public function test_all_independent_passes_only_make_open_run_ready_not_accepted():void {
  $f=$this->fixture();$this->passed($f);$p=Acceptance::position($f['run']);$this->assertTrue($p['ready']);$this->assertFalse($p['accepted']);$this->assertSame(18,$p['passed']);$this->assertSame('Open',$f['run']->fresh()->state);
 }
 public function test_final_acceptance_requires_different_manager_and_exact_ready_evidence():void {
  $f=$this->fixture();$this->passed($f);$this->actingAs($f['m'])->post($this->url($f,'accept'),['version'=>1,'note'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('acceptance');Acceptance::accept($f['n'],1,$f['run'],$f['run']->fresh()->version,'PRIVATE-FINAL-NOTE');$p=Acceptance::position($f['run']->fresh());$this->assertTrue($p['accepted']);$this->assertFalse($p['production_approved']);$this->assertSame('Accepted for declared staging scope',$p['label']);
 }
 public function test_missing_scenario_or_unchecked_result_blocks_final_acceptance():void {
  $f=$this->fixture();$this->record($f);$this->actingAs($f['n'])->post($this->url($f,'accept'),['version'=>1,'note'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('acceptance');$this->assertSame('Open',$f['run']->fresh()->state);
 }
 public function test_source_change_holds_accepted_run_and_refuses_same_run_mutations():void {
  $f=$this->fixture();$this->passed($f);Acceptance::accept($f['n'],1,$f['run'],$f['run']->fresh()->version,'Fictional');$this->changeSource();$p=Acceptance::position($f['run']->fresh());$this->assertFalse($p['accepted']);$this->assertFalse($p['source_current']);$this->actingAs($f['m'])->post($this->url($f,'reopen'),['version'=>2,'note'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('acceptance');
 }
 public function test_recording_or_review_after_source_change_is_refused_without_overwrite():void {
  $f=$this->fixture();$c=$this->record($f);$this->changeSource();$this->actingAs($f['n'])->post('/staging-checks/'.$c->id.'/review',['version'=>1,'decision'=>'accept','note'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('acceptance');$this->actingAs($f['m'])->post($this->url($f,'checks/accounts'),['version'=>1,...$this->observation()])->assertSessionHasErrors('acceptance');$this->assertSame(1,$c->fresh()->version);
 }
 public function test_editing_a_checked_result_invalidates_prior_review_and_requires_recheck():void {
  $f=$this->fixture();$c=$this->record($f);Acceptance::reviewCheck($f['n'],1,$c,1,'accept','Fictional');$this->assertSame(1,Acceptance::position($f['run'])['passed']);Acceptance::recordCheck($f['m'],1,$f['run'],'accounts',2,$this->observation('Failed'));$this->assertSame(0,Acceptance::position($f['run'])['passed']);$this->assertNull($c->fresh()->review_proof);$this->assertDatabaseHas('staging_acceptance_events',['event'=>'scenario_recorded']);
 }
 public function test_stale_check_version_unknown_or_excluded_scenario_cannot_write():void {
  $f=$this->fixture();$c=$this->record($f);$this->actingAs($f['m'])->post($this->url($f,'checks/accounts'),['version'=>0,...$this->observation()])->assertSessionHasErrors('acceptance');$this->post($this->url($f,'checks/not-real'),['version'=>0,...$this->observation()])->assertNotFound();$this->post($this->url($f,'checks/live-messaging'),['version'=>0,...$this->observation()])->assertNotFound();$this->assertDatabaseCount('staging_acceptance_checks',1);
 }
 public function test_pre_run_and_future_observation_times_are_not_evidence_for_current_run():void {
  $f=$this->fixture();$this->actingAs($f['m']);foreach([now()->subDay(),now()->addHour()]as$time){$d=$this->observation();$d['tested_at']=$time->utc()->format('Y-m-d H:i:s');$this->post($this->url($f,'checks/accounts'),['version'=>0,...$d])->assertSessionHasErrors('acceptance');}$this->assertDatabaseCount('staging_acceptance_checks',0);
 }
 public function test_accepted_run_is_locked_until_explicit_reopening():void {
  $f=$this->fixture();$this->passed($f);Acceptance::accept($f['n'],1,$f['run'],$f['run']->fresh()->version,'Fictional');$this->actingAs($f['m'])->post($this->url($f,'checks/accounts'),['version'=>2,...$this->observation()])->assertSessionHasErrors('acceptance');$this->post($this->url($f,'issues'),['version'=>0,...$this->issueData()])->assertSessionHasErrors('acceptance');$this->assertTrue(Acceptance::position($f['run']->fresh())['accepted']);
 }
 public function test_reopening_removes_final_acceptance_and_all_old_check_proofs_but_retains_history():void {
  $f=$this->fixture();$this->passed($f);Acceptance::accept($f['n'],1,$f['run'],$f['run']->fresh()->version,'Fictional');Acceptance::reopen($f['m'],1,$f['run'],$f['run']->fresh()->version,'PRIVATE-NEW-FINDING');$r=$f['run']->fresh();$p=Acceptance::position($r);$this->assertFalse($p['accepted']);$this->assertFalse($p['ready']);$this->assertSame(0,$p['passed']);$this->assertNull($r->receipt);$this->assertSame(18,Check::where('run_id',$r->id)->count());$this->assertDatabaseHas('staging_acceptance_events',['event'=>'acceptance_reopened']);
 }
 public function test_open_major_issue_blocks_even_with_all_passed_checks():void {
  $f=$this->fixture();$this->passed($f);$i=$this->issue($f);$p=Acceptance::position($f['run']);$this->assertContains($i->id,$p['blocking_issues']);$this->assertFalse($p['ready']);$raw=DB::table('staging_acceptance_issues')->first();$this->assertStringNotContainsString('PRIVATE',$raw->details);
 }
 public function test_only_minor_findings_can_be_proposed_for_deferral():void {
  $f=$this->fixture();$this->actingAs($f['m']);foreach(['Critical','Major']as$severity){$i=$this->issue($f,$severity);$this->post('/staging-findings/'.$i->id.'/propose',['version'=>1,'decision'=>'defer','reference'=>'Fictional','note'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('acceptance');$this->assertSame('Open',$i->fresh()->state);}
 }
 public function test_minor_deferral_requires_independent_evidence_and_remains_visible():void {
  $f=$this->fixture();$this->passed($f);$i=$this->issue($f,'Minor');Acceptance::proposeIssue($f['m'],1,$i,1,'defer','PRIVATE-DEFERRAL-REF','Fictional accepted scope limit');$this->assertFalse(Acceptance::position($f['run'])['ready']);Acceptance::reviewIssue($f['n'],1,$i,2,'accept','Fictional independent review');$p=Acceptance::position($f['run']);$this->assertTrue($p['ready']);$this->assertSame(1,$p['deferred_minor']);
 }
 public function test_actual_resolution_requires_different_manager_and_current_source():void {
  $f=$this->fixture();$i=$this->issue($f);Acceptance::proposeIssue($f['m'],1,$i,1,'resolve','PRIVATE-FIX-EVIDENCE','Fictional actual retest');$this->actingAs($f['m'])->post('/staging-findings/'.$i->id.'/review',['version'=>2,'decision'=>'accept','note'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('acceptance');Acceptance::reviewIssue($f['n'],1,$i,2,'accept','Fictional');$this->assertSame('Resolved',$i->fresh()->state);$this->assertTrue(Acceptance::issueVerified($i->fresh(),$f['run']));
 }
 public function test_returned_resolution_stays_open_and_editing_resolved_finding_clears_proof():void {
  $f=$this->fixture();$i=$this->issue($f);Acceptance::proposeIssue($f['m'],1,$i,1,'resolve','Fictional','Fictional');Acceptance::reviewIssue($f['n'],1,$i,2,'return','Need actual retest');$this->assertSame('Open',$i->fresh()->state);Acceptance::saveIssue($f['m'],1,$f['run'],$i,3,$this->issueData());$this->assertSame(4,$i->fresh()->version);$this->assertNull($i->fresh()->review_proof);
 }
 public function test_proposer_lost_access_or_changed_source_prevents_resolution_acceptance():void {
  $f=$this->fixture();$i=$this->issue($f);Acceptance::proposeIssue($f['m'],1,$i,1,'resolve','Fictional','Fictional');$f['m']->auth_version=2;$f['m']->save();$this->actingAs($f['n'])->post('/staging-findings/'.$i->id.'/review',['version'=>2,'decision'=>'accept','note'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('acceptance');$this->assertSame('Proposed',$i->fresh()->state);
 }
 public function test_new_run_carries_unresolved_and_deferred_findings_open_but_no_passes():void {
  $f=$this->fixture();$this->passed($f);$a=$this->issue($f,'Major');$b=$this->issue($f,'Minor');Acceptance::proposeIssue($f['m'],1,$b,1,'defer','Fictional','Fictional');Acceptance::reviewIssue($f['n'],1,$b,2,'accept','Fictional');$this->changeSource();$new=Acceptance::fork($f['m'],1,$f['run'],$this->details());$this->assertSame($f['run']->id,$new->parent_id);$this->assertDatabaseCount('staging_acceptance_runs',2);$this->assertSame(0,Check::where('run_id',$new->id)->count());$this->assertSame(2,Issue::where('run_id',$new->id)->where('state','Open')->count());$this->assertFalse(Acceptance::position($new)['ready']);$this->assertContains($a->id,Issue::where('run_id',$new->id)->pluck('parent_id')->all());
 }
 public function test_verified_resolved_finding_is_not_carried_as_open_but_forged_resolved_is():void {
  $f=$this->fixture();$i=$this->issue($f);Acceptance::proposeIssue($f['m'],1,$i,1,'resolve','Fictional','Fictional');Acceptance::reviewIssue($f['n'],1,$i,2,'accept','Fictional');$bad=$this->issue($f);DB::table('staging_acceptance_issues')->where('id',$bad->id)->update(['state'=>'Resolved']);$new=Acceptance::fork($f['m'],1,$f['run'],$this->details());$this->assertSame([$bad->id],Issue::where('run_id',$new->id)->pluck('parent_id')->all());
 }
 public function test_source_blocker_prevents_create_and_fork_without_writing_new_run():void {
  $f=$this->fixture();$this->source->value['status']='blocked';$this->source->value['blockers']=['Fictional source blocker'];$this->actingAs($f['m'])->post('/staging-acceptance',[...$this->details(),'confirmed'=>1])->assertSessionHasErrors('acceptance');$this->post($this->url($f,'new-run'),[...$this->details(),'confirmed'=>1])->assertSessionHasErrors('acceptance');$this->assertDatabaseCount('staging_acceptance_runs',1);
 }
 public function test_plaintext_forged_pass_or_accepted_state_does_not_supply_encrypted_proof():void {
  $f=$this->fixture();$c=$this->record($f,'accounts','Failed');Acceptance::reviewCheck($f['n'],1,$c,1,'accept','Fictional');DB::table('staging_acceptance_checks')->where('id',$c->id)->update(['outcome'=>'Passed']);$this->assertSame(0,Acceptance::position($f['run'])['passed']);DB::table('staging_acceptance_runs')->where('id',$f['run']->id)->update(['state'=>'Accepted','accepted_by'=>$f['n']->id,'accepted_at'=>now()]);$this->assertFalse(Acceptance::position($f['run']->fresh())['accepted']);
 }
 public function test_corrupt_check_or_run_proof_fails_closed_without_leaking_private_text():void {
  $f=$this->fixture();$c=$this->record($f);DB::table('staging_acceptance_checks')->where('id',$c->id)->update(['details'=>'CORRUPT PRIVATE']);$this->assertFalse(Acceptance::position($f['run'])['ready']);$this->actingAs($f['m'])->get($this->url($f))->assertStatus(409)->assertDontSee('CORRUPT PRIVATE');
 }
 public function test_required_audit_failure_rolls_back_recorded_check():void {
  $f=$this->fixture();AuditFault::install('acceptance_fixture_fault','staging_acceptance_events','Fictional forced audit failure','scenario_recorded');try{$this->record($f);$this->fail('audit ignored');}catch(\Illuminate\Database\QueryException){$this->assertDatabaseCount('staging_acceptance_checks',0);}
 }
 public function test_stale_actor_or_stale_run_version_cannot_accept():void {
  $f=$this->fixture();$this->passed($f);$f['n']->auth_version=2;$f['n']->save();try{Acceptance::accept($f['n'],1,$f['run'],$f['run']->fresh()->version,'Fictional');$this->fail('stale actor accepted');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(403,$e->getStatusCode());}$this->actingAs($f['n']->fresh())->post($this->url($f,'accept'),['version'=>2,'note'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('acceptance');
 }
 public function test_only_active_admin_ceo_can_view_mutate_or_export_acceptance_evidence():void {
  $f=$this->fixture();$i=$this->issue($f);foreach(['tutor','apprentice','employer','careers_adviser','inclusion_lead','staff']as$role){$u=User::factory()->create(['role'=>$role]);$this->actingAs($u);foreach(['/staging-acceptance',$this->url($f),$this->url($f,'handover.json'),'/staging-findings/'.$i->id]as$url){$this->get($url)->assertForbidden();}$this->post('/staging-acceptance',[])->assertForbidden();}
 }
 public function test_private_report_excludes_notes_references_and_always_marks_no_production_approval():void {
  $f=$this->fixture();$c=$this->record($f);$this->issue($f);$this->actingAs($f['m']);$r=$this->get($this->url($f,'handover.json'))->assertOk()->assertHeader('Cache-Control','no-store, private')->assertHeader('Referrer-Policy','no-referrer');$text=$r->getContent();$this->assertStringNotContainsString('PRIVATE-ACTUAL',$text);$this->assertStringNotContainsString('PRIVATE-FINDING',$text);$this->assertFalse($r->json('production_approved'));$this->assertFalse($r->json('private_notes_and_evidence_references_exported'));
 }
 public function test_read_only_pages_do_not_create_checks_findings_or_acceptance():void {
  $f=$this->fixture();$this->actingAs($f['m'])->get('/staging-acceptance')->assertOk();$this->get($this->url($f))->assertOk()->assertSee('Fictional scoped staging review');$this->get($this->url($f,'handover.json'))->assertOk();$this->assertDatabaseCount('staging_acceptance_checks',0);$this->assertDatabaseCount('staging_acceptance_issues',0);$this->assertSame('Open',$f['run']->fresh()->state);
 }
 public function test_mandatory_confirmations_and_post_only_actions_are_enforced():void {
  $f=$this->fixture();$this->actingAs($f['m'])->post($this->url($f,'checks/accounts'),['version'=>0,...collect($this->observation())->except('confirmed')->all()])->assertSessionHasErrors('confirmed');foreach(['accept','reopen','new-run']as$action){$this->get($this->url($f,$action))->assertStatus(405);}
 }
 public function test_invalid_issue_scenario_owner_severity_or_stale_version_refuses():void {
  $f=$this->fixture();$this->actingAs($f['m']);$d=$this->issueData();$d['scenario_id']='not-real';$this->post($this->url($f,'issues'),['version'=>0,...$d])->assertSessionHasErrors('acceptance');$d=$this->issueData();$d['owner_id']=User::factory()->create(['role'=>'tutor'])->id;$this->post($this->url($f,'issues'),['version'=>0,...$d])->assertSessionHasErrors('acceptance');$this->assertDatabaseCount('staging_acceptance_issues',0);
 }
 public function test_final_form_stales_when_evidence_changes_even_if_new_results_are_all_passed():void {
  $f=$this->fixture();$this->passed($f);$version=$f['run']->fresh()->version;$c=Check::where('run_id',$f['run']->id)->where('scenario_id','accounts')->first();Acceptance::recordCheck($f['m'],1,$f['run'],'accounts',$c->version,$this->observation());$c->refresh();Acceptance::reviewCheck($f['n'],1,$c,$c->version,'accept','Fictional fresh recheck');$this->assertTrue(Acceptance::position($f['run']->fresh())['ready']);$this->actingAs($f['n'])->post($this->url($f,'accept'),['version'=>$version,'note'=>'Fictional stale final form','confirmed'=>1])->assertSessionHasErrors('acceptance');$this->assertSame('Open',$f['run']->fresh()->state);
 }
 public function test_returned_check_requires_fresh_observation_before_new_independent_review():void {
  $f=$this->fixture();$c=$this->record($f);Acceptance::reviewCheck($f['n'],1,$c,1,'return','Need actual evidence');$this->actingAs($f['n'])->post('/staging-checks/'.$c->id.'/review',['version'=>2,'decision'=>'accept','note'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('acceptance');Acceptance::recordCheck($f['m'],1,$f['run'],'accounts',2,$this->observation());$this->assertSame('Awaiting check',$c->fresh()->review_state);
 }
}
