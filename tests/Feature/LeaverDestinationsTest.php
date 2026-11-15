<?php
namespace Tests\Feature;
use App\Models\{Apprentice,DestinationContact as Contact,DestinationProposal as Proposal,ProgrammeOutcome,StaffDuty,User};
use App\Support\{LeaverDestinations as Destinations,StaffDuties};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\{DB,Notification};
use Illuminate\Support\Str;
use Tests\Support\AuditFault;
use Tests\TestCase;
class LeaverDestinationsTest extends TestCase {
 use LazilyRefreshDatabase;
 private function fixture(string $status='Completed'):array {
  $manager=User::factory()->create(['role'=>'admin']);$other=User::factory()->create(['role'=>'ceo']);$adviser=User::factory()->create(['role'=>'careers_adviser']);
  $a=Apprentice::factory()->create(['careers_adviser_id'=>$adviser->id,'start_date'=>today()->subYears(3)]);
  $o=ProgrammeOutcome::factory()->create(['apprentice_id'=>$a->id,'context'=>['status'=>$status,'endDate'=>today()->subDays(14)->toDateString(),'sourceRef'=>'PRIVATE-STATUS-SOURCE','achievement'=>'Unknown','additional'=>'Yes','contextRef'=>'PRIVATE-SUPPORT-CONTEXT','destination'=>'Unknown','destinationBasis'=>'Unknown']]);return compact('manager','other','adviser','a','o');
 }
 private function contactData(array $f,string $result='No reply'):array {return ['request_token'=>(string)Str::uuid(),'outcome_version'=>$f['o']->fresh()->version,'contacted_on'=>today()->toDateString(),'method'=>'Phone','result'=>$result,'next_followup_on'=>$result==='No reply'?today()->addDays(7)->toDateString():null,'reference'=>'PRIVATE-CONTACT-REFERENCE','note'=>'PRIVATE-MINIMAL-CONTACT-NOTE','confirmed'=>1];}
 private function proposalData(array $f):array {return ['request_token'=>(string)Str::uuid(),'outcome_version'=>$f['o']->fresh()->version,'destination'=>'New employer','basis'=>'Reported','observed_on'=>today()->subDay()->toDateString(),'reference'=>'PRIVATE-DESTINATION-REFERENCE','confirmed'=>1];}
 private function propose(array $f,?User $u=null):Proposal {return Destinations::propose($u??$f['adviser'],1,$f['a'],$this->proposalData($f));}
 private function applied(array $f):Proposal {$x=$this->propose($f);Destinations::review($f['manager'],1,$x,1,'apply','PRIVATE-CHECK-NOTE');return $x->fresh();}
 private function show(array $f):string {return '/apprentices/'.$f['a']->id.'/leaver-followup';}
 private function url(Proposal $x,string $suffix=''):string {return '/destination-proposals/'.$x->id.($suffix?'/'.$suffix:'');}
 public function test_contact_is_encrypted_and_creates_no_destination_status_voice_or_message():void {
  $f=$this->fixture();Notification::fake();$before=$f['o']->fresh()->getAttributes();$c=Destinations::recordContact($f['adviser'],1,$f['a'],$this->contactData($f));$raw=DB::table('destination_contacts')->first();$this->assertStringNotContainsString('PRIVATE',$raw->detail);$this->assertStringNotContainsString('PRIVATE',$raw->source);$this->assertSame('No reply',$c->result);$this->assertSame($before,$f['o']->fresh()->getAttributes());$this->assertArrayNotHasKey('detail',$c->toArray());$this->assertDatabaseHas('destination_events',['event'=>'contact_recorded']);Notification::assertNothingSent();
 }
 public function test_known_sourced_end_is_required_and_invalid_or_active_end_is_refused():void {
  $f=$this->fixture('Active');$this->actingAs($f['adviser'])->post('/apprentices/'.$f['a']->id.'/destination-contacts',$this->contactData($f))->assertSessionHasErrors('destinations');$this->post('/apprentices/'.$f['a']->id.'/destination-proposals',$this->proposalData($f))->assertSessionHasErrors('destinations');$this->assertDatabaseCount('destination_contacts',0);$this->assertDatabaseCount('destination_proposals',0);
 }
 public function test_no_reply_requires_explicit_next_date_and_future_actual_contact_is_refused():void {
  $f=$this->fixture();$this->actingAs($f['adviser']);$url='/apprentices/'.$f['a']->id.'/destination-contacts';$d=$this->contactData($f);$d['next_followup_on']=null;$this->post($url,$d)->assertSessionHasErrors('destinations');$d=$this->contactData($f);$d['contacted_on']=today()->addDay()->toDateString();$this->post($url,$d)->assertSessionHasErrors('contacted_on');$d=$this->contactData($f);$d['contacted_on']=today()->subDays(15)->toDateString();$this->post($url,$d)->assertSessionHasErrors('destinations');
 }
 public function test_contact_declined_creates_no_refusal_penalty_destination_or_automatic_overdue():void {
  $f=$this->fixture();Destinations::recordContact($f['adviser'],1,$f['a'],$this->contactData($f,'Declined'));$row=Destinations::rows($f['manager'])->first();$this->assertSame('Unknown / needs source',$row['destination']);$this->assertSame('Declined',$row['contact_result']);$this->assertFalse((bool)$row['flags']['overdue']);$this->assertArrayNotHasKey('voice',$f['o']->fresh()->context);
 }
 public function test_identical_contact_request_deduplicates_and_changed_reuse_fails():void {
  $f=$this->fixture();$d=$this->contactData($f);$a=Destinations::recordContact($f['adviser'],1,$f['a'],$d);$b=Destinations::recordContact($f['adviser'],1,$f['a'],$d);$this->assertSame($a->id,$b->id);$this->assertDatabaseCount('destination_contacts',1);$d['note']='Changed';$this->actingAs($f['adviser'])->post('/apprentices/'.$f['a']->id.'/destination-contacts',$d)->assertSessionHasErrors('destinations');
 }
 public function test_latest_actual_contact_controls_followup_and_void_retains_history():void {
  $f=$this->fixture();$d=$this->contactData($f);$first=Destinations::recordContact($f['adviser'],1,$f['a'],$d);$d=$this->contactData($f,'Reached');$d['contacted_on']=today()->subDay()->toDateString();$older=Destinations::recordContact($f['adviser'],1,$f['a'],$d);$this->assertSame('No reply',Destinations::rows($f['manager'])->first()['contact_result']);
  Destinations::voidContact($f['adviser'],1,$first,1,'PRIVATE-CORRECTION');$this->assertSame('Reached',Destinations::rows($f['manager'])->first()['contact_result']);$this->assertDatabaseCount('destination_contacts',2);$this->assertNotNull($first->fresh()->voided_at);$this->assertDatabaseHas('destination_events',['event'=>'contact_voided']);
 }
 public function test_stale_void_cannot_change_a_contact():void {
  $f=$this->fixture();$c=Destinations::recordContact($f['adviser'],1,$f['a'],$this->contactData($f));$this->actingAs($f['adviser'])->post('/destination-contacts/'.$c->id.'/void',['version'=>2,'reason'=>'Fictional correction','confirmed'=>1])->assertSessionHasErrors('destinations');$this->assertNull($c->fresh()->voided_at);
 }
 public function test_programme_end_change_holds_old_contact_schedule_without_deleting_history():void {
  $f=$this->fixture();$c=Destinations::recordContact($f['adviser'],1,$f['a'],$this->contactData($f));$ctx=$f['o']->context;$ctx['endDate']=today()->subDays(13)->toDateString();$f['o']->context=$ctx;$f['o']->save();$row=Destinations::rows($f['manager'])->first();$this->assertTrue((bool)$row['flags']['source']);$this->assertNull($row['next']);$this->assertFalse(Destinations::contactCurrent($c));$this->assertDatabaseCount('destination_contacts',1);
 }
 public function test_proposal_does_not_update_reporting_until_different_manager_checks():void {
  $f=$this->fixture();$before=$f['o']->fresh()->getAttributes();$x=$this->propose($f);$this->assertSame($before,$f['o']->fresh()->getAttributes());$raw=DB::table('destination_proposals')->first();$this->assertStringNotContainsString('PRIVATE',$raw->payload);$this->assertStringNotContainsString('New employer',$raw->payload);$this->actingAs($f['adviser'])->post($this->url($x,'review'),['version'=>1,'decision'=>'apply','note'=>'Fictional','confirmed'=>1])->assertForbidden();$this->assertSame('Pending',$x->fresh()->state);
 }
 public function test_apply_changes_only_four_destination_fields_and_keeps_reported_basis():void {
  $f=$this->fixture();$before=$f['o']->context;$x=$this->applied($f);$after=$f['o']->fresh()->context;foreach($before as$key=>$value){if(!in_array($key,Destinations::DESTINATION_KEYS,true)){$this->assertSame($value,$after[$key]);}}
  $this->assertSame('Reported',$after['destinationBasis']);$this->assertSame('New employer',$after['destination']);$this->assertSame(2,$f['o']->fresh()->version);$this->assertSame('Applied',$x->state);$this->assertTrue(Destinations::appliedCurrent($x));$this->assertSame(1,count($f['o']->fresh()->history));$this->assertDatabaseHas('destination_events',['event'=>'destination_applied']);$this->assertDatabaseCount('signed_review_records',0);
 }
 public function test_manager_cannot_approve_own_proposal_but_different_manager_can():void {
  $f=$this->fixture();$x=$this->propose($f,$f['manager']);$this->actingAs($f['manager'])->post($this->url($x,'review'),['version'=>1,'decision'=>'apply','note'=>'Fictional check','confirmed'=>1])->assertSessionHasErrors('destinations');$this->actingAs($f['other'])->post($this->url($x,'review'),['version'=>1,'decision'=>'apply','note'=>'Fictional independent check','confirmed'=>1])->assertSessionHasNoErrors();$this->assertSame('Applied',$x->fresh()->state);
 }
 public function test_return_keeps_original_context_and_requires_new_proposal_for_changes():void {
  $f=$this->fixture();$x=$this->propose($f);$before=$f['o']->fresh()->getAttributes();$this->actingAs($f['manager'])->post($this->url($x,'review'),['version'=>1,'decision'=>'return','note'=>'PRIVATE-RETURN-NOTE','confirmed'=>1])->assertSessionHasNoErrors();$this->assertSame('Returned',$x->fresh()->state);$this->assertSame($before,$f['o']->fresh()->getAttributes());$this->post($this->url($x,'review'),['version'=>2,'decision'=>'apply','note'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('destinations');
 }
 public function test_stale_outcome_or_profile_source_blocks_apply_and_preserves_new_facts():void {
  $f=$this->fixture();$x=$this->propose($f);$ctx=$f['o']->context;$ctx['achievement']='Awaiting result';$f['o']->context=$ctx;$f['o']->version++;$f['o']->save();$this->actingAs($f['manager'])->post($this->url($x,'review'),['version'=>1,'decision'=>'apply','note'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('destinations');$this->assertSame('Awaiting result',$f['o']->fresh()->context['achievement']);$this->assertSame('Unknown',$f['o']->fresh()->context['destination']);
 }
 public function test_bad_destination_unknown_future_before_end_and_missing_source_fail_validation():void {
  $f=$this->fixture();$this->actingAs($f['adviser']);$url='/apprentices/'.$f['a']->id.'/destination-proposals';foreach([['destination','Unknown'],['basis','Assumed'],['observed_on',today()->addDay()->toDateString()],['reference','']]as[$key,$value]){$d=$this->proposalData($f);$d[$key]=$value;$this->post($url,$d)->assertSessionHasErrors($key);}$d=$this->proposalData($f);$d['observed_on']=today()->subDays(15)->toDateString();$this->post($url,$d)->assertSessionHasErrors('destinations');$this->assertDatabaseCount('destination_proposals',0);
 }
 public function test_old_form_outcome_version_and_missing_confirmation_fail_without_writes():void {
  $f=$this->fixture();$this->actingAs($f['adviser']);$d=$this->proposalData($f);unset($d['confirmed']);$this->post('/apprentices/'.$f['a']->id.'/destination-proposals',$d)->assertSessionHasErrors('confirmed');$d=$this->proposalData($f);$d['outcome_version']=2;$this->post('/apprentices/'.$f['a']->id.'/destination-proposals',$d)->assertSessionHasErrors('destinations');$d=$this->contactData($f);$d['outcome_version']=2;$this->post('/apprentices/'.$f['a']->id.'/destination-contacts',$d)->assertSessionHasErrors('destinations');$this->assertDatabaseCount('destination_events',0);
 }
 public function test_destination_request_is_idempotent_and_changed_reuse_is_rejected():void {
  $f=$this->fixture();$d=$this->proposalData($f);$a=Destinations::propose($f['adviser'],1,$f['a'],$d);$b=Destinations::propose($f['adviser'],1,$f['a'],$d);$this->assertSame($a->id,$b->id);$d['basis']='Verified';$this->actingAs($f['adviser'])->post('/apprentices/'.$f['a']->id.'/destination-proposals',$d)->assertSessionHasErrors('destinations');$this->assertDatabaseCount('destination_proposals',1);
 }
 public function test_pending_withdrawal_does_not_change_outcome_and_cannot_reopen():void {
  $f=$this->fixture();$x=$this->propose($f);$before=$f['o']->fresh()->getAttributes();Destinations::withdraw($f['adviser'],1,$x,1,'Fictional correction');$this->assertSame('Withdrawn',$x->fresh()->state);$this->assertSame($before,$f['o']->fresh()->getAttributes());$this->actingAs($f['manager'])->post($this->url($x,'review'),['version'=>2,'decision'=>'apply','note'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('destinations');
 }
 public function test_applied_withdrawal_restores_only_prior_destination_keys_including_absent_keys():void {
  $f=$this->fixture();$original=$f['o']->context;$x=$this->applied($f);Destinations::withdraw($f['manager'],1,$x,2,'Fictional evidence correction');$this->assertSame($original,$f['o']->fresh()->context);$this->assertSame(3,$f['o']->fresh()->version);$this->assertSame('Withdrawn',$x->fresh()->state);$this->assertDatabaseCount('destination_events',3);
 }
 public function test_applied_withdrawal_requires_independent_manager_and_rejects_later_context_changes():void {
  $f=$this->fixture();$x=$this->applied($f);$this->actingAs($f['adviser'])->post($this->url($x,'withdraw'),['version'=>2,'reason'=>'Fictional','confirmed'=>1])->assertForbidden();$ctx=$f['o']->fresh()->context;$ctx['voice']='Recorded';$f['o']->context=$ctx;$f['o']->version=3;$f['o']->save();$this->actingAs($f['manager'])->post($this->url($x,'withdraw'),['version'=>2,'reason'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('destinations');$this->assertSame('Recorded',$f['o']->fresh()->context['voice']);$this->assertSame('New employer',$f['o']->fresh()->context['destination']);
 }
 public function test_audit_failure_rolls_back_destination_context_proposal_and_receipt():void {
  $f=$this->fixture();$x=$this->propose($f);$before=$f['o']->fresh()->getAttributes();AuditFault::install('destination_fixture_fault','destination_events','Fictional forced audit failure','destination_applied');try{Destinations::review($f['manager'],1,$x,1,'apply','Fictional');$this->fail('audit error ignored');}catch(\Illuminate\Database\QueryException){$this->assertSame($before,$f['o']->fresh()->getAttributes());$this->assertSame('Pending',$x->fresh()->state);$this->assertNull($x->fresh()->receipt);}
 }
 public function test_unassigned_adviser_tutor_apprentice_employer_and_inclusion_have_no_access():void {
  $f=$this->fixture();$x=$this->propose($f);foreach(['careers_adviser','tutor','apprentice','employer','inclusion_member']as$role){$u=User::factory()->create(['role'=>$role]);$this->actingAs($u);$this->get($this->show($f))->assertForbidden();$this->get($this->url($x))->assertForbidden();$this->get('/leaver-followup.csv')->assertStatus($role==='careers_adviser'?200:403);$this->post('/apprentices/'.$f['a']->id.'/destination-contacts',$this->contactData($f))->assertForbidden();}
 }
 public function test_scope_changes_revoke_existing_access():void {
  $f=$this->fixture();$x=$this->propose($f);$f['a']->careers_adviser_id=null;$f['a']->save();$this->actingAs($f['adviser'])->get($this->url($x))->assertForbidden();$this->get('/leaver-followup')->assertOk()->assertDontSee($f['a']->reference);
 }
 public function test_stale_actor_auth_version_cannot_contact_or_apply():void {
  $f=$this->fixture();$x=$this->propose($f);$f['manager']->auth_version=2;$f['manager']->save();try{Destinations::review($f['manager'],1,$x,1,'apply','Fictional');$this->fail('stale actor accepted');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(403,$e->getStatusCode());}$this->assertSame('Pending',$x->fresh()->state);
 }
 public function test_queue_filters_search_unknown_reported_verified_pending_and_assignment():void {
  $f=$this->fixture();$this->applied($f);$g=$this->fixture();$g['a']->careers_adviser_id=null;$g['a']->save();$this->actingAs($f['manager'])->get('/leaver-followup?search='.$f['a']->reference.'&show=reported')->assertOk()->assertSee($f['a']->reference)->assertDontSee($g['a']->reference)->assertSee('1 current independently checked proposals');$this->get('/leaver-followup?show=assignments')->assertSee($g['a']->reference)->assertDontSee($f['a']->reference);$this->get('/leaver-followup?show=wrong')->assertSessionHasErrors('show');
 }
 public function test_existing_manual_verified_destination_is_not_assumed_independently_checked():void {
  $f=$this->fixture();$c=$f['o']->context;$c+=['destinationDate'=>today()->toDateString(),'destinationRef'=>'Fictional old manual source'];$c['destination']='Further learning';$c['destinationBasis']='Verified';$f['o']->context=$c;$f['o']->save();$r=Destinations::rows($f['manager'])->first();$this->assertSame('Verified',$r['basis']);$this->assertFalse($r['checked']);$this->assertFalse((bool)$r['flags']['unknown']);
 }
 public function test_future_or_unsourced_existing_destination_is_unknown_and_flagged_not_trusted():void {
  $f=$this->fixture();$c=$f['o']->context;$c['destination']='New employer';$c['destinationBasis']='Verified';$c['destinationDate']=today()->addDay()->toDateString();$f['o']->context=$c;$f['o']->save();$r=Destinations::rows($f['manager'])->first();$this->assertSame('Unknown',$r['basis']);$this->assertTrue((bool)$r['flags']['source']);
 }
 public function test_contact_overdue_and_today_filters_use_explicit_dates_not_default_intervals():void {
  $f=$this->fixture();$d=$this->contactData($f);$d['next_followup_on']=today()->toDateString();Destinations::recordContact($f['adviser'],1,$f['a'],$d);$this->assertTrue((bool)Destinations::rows($f['manager'])->first()['flags']['today']);$this->travel(1)->days();$this->assertTrue((bool)Destinations::rows($f['manager'])->first()['flags']['overdue']);
 }
 public function test_csv_is_scoped_filtered_formula_safe_and_excludes_private_context_and_sources():void {
  $f=$this->fixture();$f['a']->first_name='=HYPERLINK("fictional")';$f['a']->save();Destinations::recordContact($f['adviser'],1,$f['a'],$this->contactData($f));$g=$this->fixture();$this->actingAs($f['adviser']);$response=$this->get('/leaver-followup.csv?search='.$f['a']->reference)->assertOk()->assertHeader('Cache-Control','no-store, private');$csv=$response->streamedContent();$this->assertStringContainsString("'=HYPERLINK",$csv);$this->assertStringNotContainsString($g['a']->reference,$csv);foreach(['PRIVATE-STATUS','PRIVATE-SUPPORT','PRIVATE-CONTACT','PRIVATE-MINIMAL']as$value){$this->assertStringNotContainsString($value,$csv);}$this->assertStringContainsString('Current independently checked proposal',$csv);foreach(['=1',' +1','@x','-x',"\tformula"]as$value){$this->assertStringStartsWith("'",Destinations::csvCell($value));}
 }
 public function test_date_range_pagination_and_invalid_inputs_are_checked():void {
  $f=$this->fixture();$this->actingAs($f['manager'])->get('/leaver-followup?end_from='.today()->toDateString())->assertOk()->assertDontSee($f['a']->reference);$this->get('/leaver-followup?end_from=2026-02-03&end_to=2026-02-01')->assertSessionHasErrors('end_to');$this->get('/leaver-followup?page=0')->assertSessionHasErrors('page');$this->get($this->show($f).'?contact_page=wrong')->assertSessionHasErrors('contact_page');
 }
 public function test_corrupt_proposal_source_payload_and_receipt_cannot_apply_or_reverse():void {
  $f=$this->fixture();$x=$this->propose($f);DB::table('destination_proposals')->where('id',$x->id)->update(['payload'=>'CORRUPT PRIVATE']);$this->assertFalse(Destinations::proposalCurrent($x->fresh()));$this->actingAs($f['manager'])->get($this->url($x))->assertStatus(409);$this->post($this->url($x,'review'),['version'=>1,'decision'=>'apply','note'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('destinations');$y=$this->applied($f);DB::table('destination_proposals')->where('id',$y->id)->update(['receipt'=>'CORRUPT PRIVATE']);$this->assertFalse(Destinations::appliedCurrent($y->fresh()));$this->post($this->url($y,'withdraw'),['version'=>2,'reason'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('destinations');
 }
 public function test_read_only_pages_never_contact_or_apply_and_show_private_notes_only_to_authorised_staff():void {
  $f=$this->fixture();Destinations::recordContact($f['adviser'],1,$f['a'],$this->contactData($f));$x=$this->propose($f);$before=$f['o']->fresh()->getAttributes();$this->actingAs($f['adviser'])->get($this->show($f))->assertOk()->assertSee('PRIVATE-MINIMAL-CONTACT-NOTE');$this->get($this->url($x))->assertOk()->assertSee('PRIVATE-DESTINATION-REFERENCE');$this->get('/leaver-followup')->assertOk()->assertDontSee('PRIVATE-MINIMAL');$this->assertSame($before,$f['o']->fresh()->getAttributes());$this->assertDatabaseCount('destination_events',2);
 }
 public function test_additional_careers_duty_is_honoured_then_removed_immediately():void {
  $f=$this->fixture();$u=User::factory()->create(['role'=>'tutor']);$d=StaffDuty::create(['user_id'=>$u->id,'careers_adviser'=>true,'history'=>[]]);$f['a']->careers_adviser_id=$u->id;$f['a']->save();$this->actingAs($u)->get($this->show($f))->assertOk();$d->careers_adviser=false;$d->version++;$d->save();$this->get($this->show($f))->assertForbidden();$this->get('/leaver-followup')->assertForbidden();
 }
 public function test_corrupt_contact_holds_schedule_but_staff_can_review_and_void_its_history():void {
  $f=$this->fixture();$c=Destinations::recordContact($f['adviser'],1,$f['a'],$this->contactData($f));DB::table('destination_contacts')->where('id',$c->id)->update(['detail'=>'CORRUPT PRIVATE']);$this->assertFalse(Destinations::contactCurrent($c->fresh()));$this->actingAs($f['adviser'])->get($this->show($f))->assertOk()->assertSee('Contact detail cannot be verified')->assertDontSee('CORRUPT PRIVATE');$this->post('/destination-contacts/'.$c->id.'/void',['version'=>1,'reason'=>'Fictional repair','confirmed'=>1])->assertSessionHasNoErrors();$this->assertNotNull($c->fresh()->voided_at);
 }
 public function test_mutations_are_post_only_and_existing_careers_retention_links_are_present():void {
  $f=$this->fixture();$x=$this->propose($f);$this->actingAs($f['manager']);foreach(['review','withdraw']as$action){$this->get($this->url($x,$action))->assertStatus(405);}$this->get('/careers')->assertSee('Leaver follow-up and destination checks');$this->get('/reports/retention')->assertSee('Leaver follow-up and destination checks');$this->get('/apprentices/'.$f['a']->id.'/outcome')->assertSee('Leaver follow-up and destination checks');
 }
}
