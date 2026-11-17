<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Models\{Apprentice,User,Employer,ApprenticeEntryContext as Entry,EntryContextEvent as Event,ProgrammeOutcome,DestinationMilestone as Task,DestinationMilestoneCheck as Check,SupportConcern};
use App\Support\{EntryContext,DestinationMilestones as Followup,RetentionAnalysis};
class RecruitmentRetention62Test extends TestCase {
 use LazilyRefreshDatabase;
 private function admin():User {return User::factory()->create(['role'=>'admin','active'=>true]);}
 private function input(array $extra=[]):array {$v=[];foreach(EntryContext::choices() as $key=>$values){$v[$key]='Unknown';}return array_merge($v,['stage'=>'Enrolment','confirmed_on'=>today()->toDateString()],$extra);}
 private function ended(Apprentice $a,string $end='2025-11-30'):void {ProgrammeOutcome::create(['apprentice_id'=>$a->id,'context'=>['status'=>'Withdrawn','endDate'=>$end,'sourceRef'=>'Synthetic checked source']]);Followup::sync($a);}
 private function checkInput(Task $t,array $extra=[]):array {return array_merge(['version'=>$t->version,'observed_on'=>today()->toDateString(),'result'=>'Reached','destination'=>'Same employer','basis'=>'Reported','continuity'=>'Unknown','source'=>'Fictional call record'],$extra);}
 public function test_entry_routes_are_independent_and_one_update_reaches_profile_queue_report():void {
  $a=Apprentice::factory()->create(['start_date'=>'2025-01-01']);$u=$this->admin();$this->actingAs($u);
  $this->post(route('retention.entry.save',$a),array_merge($this->input(['recruitment_route'=>'School or careers adviser','employment_route'=>'Family introduction','family_owned'=>'Yes','family_owner_manager'=>'No','family_supervisor'=>'No']),['version'=>0]))->assertRedirect()->assertSessionHasNoErrors();
  $entry=Entry::firstOrFail();$this->assertSame('Not applicable',$entry->relationship);$this->assertSame('Family introduction',$entry->employment_route);$this->assertDatabaseCount('support_concerns',0);
  $this->get(route('retention.entry',$a))->assertOk()->assertSee('School or careers adviser');
  $this->get('/apprentices/'.$a->id)->assertOk()->assertSee('Recruitment and starting circumstances');
  $this->get('/retention-admin')->assertOk()->assertSee($a->reference);
  $this->get('/reports/retention?factor=recruitment_route')->assertOk()->assertSee('School or careers adviser')->assertSee('Small sample: exploratory');
 }
 public function test_partial_application_is_allowed_but_enrolment_needs_honest_responses():void {
  $a=Apprentice::factory()->create();$this->actingAs($this->admin())->post(route('retention.entry.save',$a),['version'=>0,'stage'=>'Application','recruitment_route'=>'Social media'])->assertSessionHasNoErrors();
  $this->post(route('retention.entry.save',$a),['version'=>1,'stage'=>'Enrolment','reason'=>'Confirmation'])->assertSessionHasErrors('employment_route');
 }
 public function test_unknowns_preferences_and_no_inferred_relationship_survive():void {
  $a=Apprentice::factory()->create();$e=EntryContext::save($this->admin(),$a,$this->input(['family_owned'=>'Yes','family_owner_manager'=>'Prefer not to say','family_supervisor'=>'Prefer not to say']),0);
  $this->assertSame('Prefer not to say',$e->relationship);$this->assertNull($e->employment_start_date);$this->assertDatabaseCount('support_concerns',0);
 }
 public function test_corrections_keep_original_and_reject_stale_updates_and_personal_names():void {
  $a=Apprentice::factory()->create();$u=$this->admin();$this->actingAs($u);$e=EntryContext::save($u,$a,$this->input(['recruitment_route'=>'Social media','relative_name'=>'Do not store']),0);
  $this->assertArrayNotHasKey('relative_name',$e->getAttributes());
  $this->post(route('retention.entry.save',$a),$this->input(['version'=>1,'recruitment_route'=>'Employer referral']))->assertSessionHasErrors('reason');
  $this->post(route('retention.entry.save',$a),$this->input(['version'=>1,'recruitment_route'=>'Employer referral','reason'=>'Apprentice corrected original answer']))->assertSessionHasNoErrors();
  $this->assertSame('Social media',Event::orderByDesc('id')->first()->payload['before']['recruitment_route']);
  $this->post(route('retention.entry.save',$a),$this->input(['version'=>1,'reason'=>'Stale']))->assertSessionHasErrors('version');
 }
 public function test_later_employment_is_separate_and_dates_are_checked():void {
  $a=Apprentice::factory()->create(['start_date'=>'2025-01-01']);$u=$this->admin();EntryContext::save($u,$a,$this->input(['family_owned'=>'No']),0);
  $this->actingAs($u)->post(route('retention.employment',$a),['effective_on'=>today()->toDateString(),'family_owned'=>'Yes','family_owner_manager'=>'Yes','relationship'=>'Sibling','reason'=>'Supervisor changed'])->assertSessionHasNoErrors();
  $this->assertSame('No',Entry::first()->family_owned);$this->assertSame('Employment',Event::orderByDesc('id')->first()->kind);
  $this->post(route('retention.entry.save',$a),$this->input(['version'=>1,'already_employed'=>'Yes','employment_start_date'=>'2026-01-01','reason'=>'Date check']))->assertSessionHasErrors('employment_start_date');
 }
 public function test_missing_recruitment_does_not_block_support_and_existing_contacts():void {
  $a=Apprentice::factory()->create(['start_date'=>'2025-01-01']);$this->ended($a);$u=$this->admin();$this->actingAs($u)->get('/apprentices/'.$a->id.'/support')->assertOk();
  $this->get(route('destinations.show',$a))->assertOk()->assertSee('3, 6 and 12-month destination checks');$this->assertDatabaseCount('apprentice_entry_contexts',0);
 }
 public function test_all_new_actions_exports_and_timeline_are_denied_to_other_roles():void {
  $a=Apprentice::factory()->create(['start_date'=>'2025-01-01']);$this->ended($a);$t=Task::first();
  foreach(['tutor','mentor','careers_adviser','employer','apprentice','centre_manager'] as $role){$u=User::factory()->create(['role'=>$role]);$this->actingAs($u);
   foreach([route('retention.entry',$a),route('retention.timeline',$a),'/retention-admin','/reports/retention?export=csv'] as $url){$this->get($url)->assertForbidden();}
   $this->post(route('retention.entry.save',$a),[])->assertForbidden();$this->post(route('retention.employment',$a),[])->assertForbidden();$this->post(route('retention.milestone.check',$t),[])->assertForbidden();$this->post(route('retention.milestone.assign',$t),[])->assertForbidden();$this->post(route('retention.quality.assign',$a),[])->assertForbidden();
  }
 }
 public function test_tasks_use_calendar_months_and_reconciliation_is_idempotent():void {
  $a=Apprentice::factory()->create(['start_date'=>'2025-01-01']);$this->ended($a,'2025-01-31');Followup::sync($a);$this->assertDatabaseCount('destination_milestones',3);
  $this->assertSame('2025-04-30',Task::where('months',3)->first()->due_on->toDateString());$this->assertSame('2025-07-31',Task::where('months',6)->first()->due_on->toDateString());
 }
 public function test_changed_exit_date_supersedes_tasks_without_deleting_checks():void {
  $a=Apprentice::factory()->create(['start_date'=>'2025-01-01']);$this->ended($a);$u=$this->admin();$t=Task::where('months',3)->first();Followup::record($u,$t,$this->checkInput($t));
  $o=ProgrammeOutcome::first();$c=$o->context;$c['endDate']='2025-12-01';$o->context=$c;$o->save();Followup::sync($a);
  $this->assertSame('Superseded',$t->fresh()->state);$this->assertDatabaseCount('destination_milestone_checks',1);$this->assertDatabaseCount('destination_milestones',6);
  $this->actingAs($u)->post(route('retention.milestone.check',$t),$this->checkInput($t))->assertSessionHasErrors('version');
 }
 public function test_no_reply_requires_retry_and_does_not_claim_destination():void {
  $a=Apprentice::factory()->create(['start_date'=>'2025-01-01']);$this->ended($a);$t=Task::where('months',3)->first();$this->actingAs($this->admin());
  $this->post(route('retention.milestone.check',$t),$this->checkInput($t,['result'=>'No reply','destination'=>'Unknown','basis'=>'Unknown']))->assertSessionHasErrors('retry_on');
  $this->post(route('retention.milestone.check',$t),$this->checkInput($t,['result'=>'No reply','destination'=>'Unknown','basis'=>'Unknown','retry_on'=>today()->addDay()->toDateString()]))->assertSessionHasNoErrors();
  $this->assertSame('Open',$t->fresh()->state);$summary=Followup::summary(collect([$t->fresh()]),Check::all()->keyBy('milestone_id'));$this->assertSame(0,$summary[3]['known']);$this->assertSame(1,$summary[3]['unknown']);
 }
 public function test_destination_snapshot_is_not_sustained_employment_and_retains_exit():void {
  $a=Apprentice::factory()->create(['start_date'=>'2025-01-01']);$this->ended($a);$t=Task::where('months',6)->first();$u=$this->admin();$o=ProgrammeOutcome::first()->context;
  Followup::record($u,$t,$this->checkInput($t));$summary=Followup::summary(collect([$t]),Check::all()->keyBy('milestone_id'));$this->assertSame(0,$summary[6]['uninterrupted_from_exit']);$this->assertSame($o,ProgrammeOutcome::first()->context);
  $t->refresh();Followup::record($u,$t,$this->checkInput($t,['continuity'=>'Continuous employment','continuous_from'=>'2025-11-30','basis'=>'Verified']));$summary=Followup::summary(collect([$t]),Check::orderBy('id')->get()->keyBy('milestone_id'));$this->assertSame(1,$summary[6]['verified_uninterrupted']);$this->assertDatabaseCount('destination_milestone_checks',2);
 }
 public function test_future_early_and_mismatched_continuity_checks_are_rejected():void {
  $a=Apprentice::factory()->create(['start_date'=>'2025-01-01']);$this->ended($a);$t=Task::where('months',3)->first();$this->actingAs($this->admin());
  $this->post(route('retention.milestone.check',$t),$this->checkInput($t,['observed_on'=>'2025-12-01']))->assertSessionHasErrors('observed_on');
  $this->post(route('retention.milestone.check',$t),$this->checkInput($t,['continuity'=>'Continuous training','continuous_from'=>'2025-11-30']))->assertSessionHasErrors('continuous_from');
 }
 public function test_queues_assignment_and_timeline_render_and_use_current_data():void {
  $u=$this->admin();$a=Apprentice::factory()->create(['start_date'=>'2025-01-01']);$this->ended($a);$this->actingAs($u);$t=Task::first();
  $this->post(route('retention.quality.assign',$a),['version'=>0,'owner_id'=>$u->id,'due_on'=>today()->toDateString()])->assertSessionHasNoErrors();
  $this->post(route('retention.milestone.assign',$t),['version'=>1,'owner_id'=>$u->id])->assertSessionHasNoErrors();
  $this->get('/retention-admin?mine=1')->assertOk()->assertSee($a->reference);$this->get('/retention-admin?show=followup&mine=1')->assertOk()->assertSee($a->reference);
  $this->get(route('retention.timeline',$a))->assertOk()->assertSee('Destination follow-up scheduled');
 }
 public function test_known_denominators_active_unknown_and_transfer_are_distinct():void {
  $rows=[];foreach([['Completed','Achieved'],['Completed','Not achieved'],['Completed','Unknown'],['Withdrawn','Unknown'],['Active','Unknown'],['Transferred','Unknown'],['Unknown','Unknown']] as [$status,$achievement]){$rows[]=['factor'=>'Family introduction','status'=>$status,'achievement'=>$achievement,'days_before_leaving'=>$status==='Withdrawn'?100:null,'concern_codes'=>'No recorded concerns','exit_destination'=>'Unknown'];}
  $g=RetentionAnalysis::groups($rows)->first();$this->assertSame(4,$g['terminal']);$this->assertSame(25.0,$g['withdrawal_rate']);$this->assertSame(2,$g['known_results']);$this->assertSame(50.0,$g['achievement_rate']);$this->assertSame(1,$g['missing_results']);$this->assertEquals(100,$g['median_leaving_days']);
 }
 public function test_filtering_and_csv_export_are_aggregate_and_audited():void {
  $u=$this->admin();$a=Apprentice::factory()->create(['start_date'=>'2025-01-01']);EntryContext::save($u,$a,$this->input(['already_employed'=>'Yes','recruitment_route'=>'Social media']),0);
  $this->actingAs($u)->get('/reports/retention?factor=recruitment_route&prior=No')->assertOk()->assertSee('0 records included');
  $res=$this->get('/reports/retention?factor=recruitment_route&prior=Yes&export=csv');$res->assertOk();$csv=$res->streamedContent();$this->assertStringContainsString('Social media',$csv);$this->assertStringNotContainsString($a->reference,$csv);$this->assertDatabaseHas('retention_report_exports',['actor_id'=>$u->id]);
 }

 public function test_hidden_relationship_is_normalised_before_enrolment_validation():void {
  $a=Apprentice::factory()->create();$e=EntryContext::save($this->admin(),$a,$this->input(['family_owner_manager'=>'No','family_supervisor'=>'No','relationship'=>null]),0);$this->assertSame('Not applicable',$e->relationship);
 }
 public function test_closed_support_without_a_closure_date_stays_unknown_and_exposure_stops_at_exit():void {
  $p=new \App\Models\SupportPlan;$p->active_plan=['approved_at'=>'2025-01-01T10:00:00Z'];$p->status='Closed';$s=\App\Support\SupportExposure::summary(collect([$p]));$this->assertNull($s['days']);$this->assertSame(1,$s['unknown']);
  $p->closed_at='2025-12-31';$s=\App\Support\SupportExposure::summary(collect([$p]),'2025-01-10');$this->assertSame(10,$s['days']);
 }
 public function test_recruitment_partial_correction_keeps_other_known_fields():void {
  $u=$this->admin();$a=Apprentice::factory()->create();EntryContext::save($u,$a,$this->input(['recruitment_route'=>'Social media','employment_route'=>'Family introduction']),0);
  EntryContext::save($u,$a,['recruitment_route'=>'Employer referral'],1,'Correct original source');$this->assertSame('Family introduction',Entry::first()->employment_route);
 }
 public function test_existing_exit_save_schedules_and_assigns_all_three_checks_without_entry_data():void {
  $a=Apprentice::factory()->create(['start_date'=>'2025-01-01']);$u=$this->admin();$this->actingAs($u)->post(route('retention.save',$a),['version'=>0,'context'=>['status'=>'Withdrawn','endDate'=>'2025-11-30','sourceRef'=>'Synthetic exit'],'followup_owner_id'=>$u->id])->assertSessionHasNoErrors();$this->assertSame(3,Task::where('owner_id',$u->id)->count());$this->assertDatabaseCount('apprentice_entry_contexts',0);
 }

 public function test_additive_migration_keeps_existing_outcomes_and_does_not_invent_history():void {
  $migration=require database_path('migrations/2026_11_16_620000_create_recruitment_and_followup_records.php');$migration->down();
  $a=Apprentice::factory()->create();$o=ProgrammeOutcome::create(['apprentice_id'=>$a->id,'context'=>['status'=>'Unknown','destination'=>'Unknown']]);
  $p=\App\Models\SupportPlan::create(['apprentice_id'=>$a->id,'concern_ids'=>[],'draft'=>[]]);$p->status='Closed';$p->save();
  $migration->up();$this->assertSame($o->context,$o->fresh()->context);$this->assertNull($p->fresh()->closed_at);$this->assertDatabaseCount('apprentice_entry_contexts',0);$this->assertDatabaseCount('entry_context_events',0);$this->assertDatabaseCount('destination_milestone_checks',0);
 }
 public function test_a_later_destination_is_not_relabelled_as_the_exit_destination():void {
  $c=['endDate'=>'2025-01-01','destinationDate'=>'2025-04-01','destination'=>'New employer','destinationBasis'=>'Verified','destinationRef'=>'Synthetic source'];
  $this->assertSame('Unknown at exit',RetentionAnalysis::exitDestination($c));$c['destinationDate']='2025-01-01';$this->assertSame('New employer',RetentionAnalysis::exitDestination($c));
 }
}
