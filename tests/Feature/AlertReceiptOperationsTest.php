<?php
namespace Tests\Feature;
use App\Models\{User,Apprentice,ApprenticeAlertDelivery as Delivery,AlertReceiptBatch as Batch,AlertReceiptRecord as Receipt,AlertFollowupCase as Followup};
use App\Support\{AlertReceiptOperations as Ops,ReviewedImports};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\{DB,Http,Notification};
use Illuminate\Support\Str;
use Tests\Support\AuditFault;
use Tests\TestCase;
class AlertReceiptOperationsTest extends TestCase {
 use LazilyRefreshDatabase;
 private function fixture(string $channel='sms'):array {
  $m=User::factory()->create(['role'=>'admin']);$n=User::factory()->create(['role'=>'ceo']);$u=User::factory()->create(['role'=>'apprentice']);$a=Apprentice::factory()->create(['user_id'=>$u->id]);$source=['apprentice_id'=>$a->id,'recipient_id'=>$u->id,'items'=>[['title'=>'PRIVATE-TASK-TITLE']]];
  $d=Delivery::create(['id'=>(string)Str::uuid(),'apprentice_id'=>$a->id,'recipient_id'=>$u->id,'channel'=>$channel,'cycle_date'=>today(),'source'=>$source,'source_hash'=>ReviewedImports::hash($source),'recipient_hash'=>str_repeat('r',64),'preference_revision'=>0,'auth_version'=>1,'config_hash'=>str_repeat('c',64),'state'=>'Accepted','reserved_at'=>now()->subMinutes(10),'completed_at'=>now()->subMinutes(9),'provider_id'=>$channel==='sms'?'FICTIONAL-'.Str::random(16):hash('sha256',Str::random(20)),'history'=>[]]);return compact('m','n','u','a','d');
 }
 private function row(Delivery $d,string $outcome='Delivered',?string $time=null):array {return ['delivery_id'=>$d->id,'channel'=>$d->channel,'provider_id'=>$d->provider_id,'outcome'=>$outcome,'occurred_at_utc'=>$time??now()->subMinutes(2)->utc()->format('Y-m-d H:i:s'),'evidence_kind'=>$d->channel==='sms'?'sms_delivery_report':($outcome==='Bounced'?'bounce_report':'email_delivery_report'),'source_reference'=>'PRIVATE-PROVIDER-SOURCE'];}
 private function csv(array $rows):string {$f=fopen('php://temp','r+');fputcsv($f,Ops::HEADERS,',','"','');foreach($rows as$row){fputcsv($f,array_values($row),',','"','');}rewind($f);$s=stream_get_contents($f);fclose($f);return $s;}
 private function preview(array $f,string $outcome='Delivered',?string $time=null):Batch {return Ops::preview($f['m'],1,(string)Str::uuid(),$this->csv([$this->row($f['d'],$outcome,$time)]),'PRIVATE-MAPPING-REF');}
 private function applied(array $f,string $outcome='Delivered',?string $time=null):Batch {$b=$this->preview($f,$outcome,$time);Ops::review($f['n'],1,$b,1,'apply','PRIVATE-INDEPENDENT-CHECK');return $b->fresh();}
 private function url(Batch $b,string $action=''):string {return '/receipt-batches/'.$b->id.($action?'/'.$action:'');}
 private function caseUrl(Followup $c,string $action):string {return '/receipt-cases/'.$c->id.'/'.$action;}
 public function test_preview_is_encrypted_read_only_and_does_not_send_or_change_preferences():void {
  $f=$this->fixture();Http::preventStrayRequests();Notification::fake();$before=$f['d']->fresh()->getAttributes();$profile=$f['a']->fresh()->getAttributes();$b=$this->preview($f);$raw=DB::table('alert_receipt_batches')->first();$this->assertStringNotContainsString('PRIVATE',$raw->rows);$this->assertStringNotContainsString('Delivered',$raw->rows);$this->assertSame('Preview',$b->state);$this->assertSame($before,$f['d']->fresh()->getAttributes());$this->assertSame($profile,$f['a']->fresh()->getAttributes());$this->assertDatabaseCount('alert_receipt_records',0);$this->assertArrayNotHasKey('rows',$b->toArray());Notification::assertNothingSent();
 }
 public function test_independent_apply_records_outcome_but_original_attempt_and_seen_stay_unchanged():void {
  $f=$this->fixture();$before=$f['d']->fresh()->getAttributes();$b=$this->applied($f);$p=Ops::position($f['d']->fresh());$this->assertSame('Delivered',$p['outcome']);$this->assertSame('Applied',$b->state);$this->assertSame($before,$f['d']->fresh()->getAttributes());$this->assertNull($f['d']->fresh()->seen_at);$this->assertDatabaseHas('alert_receipt_events',['event'=>'receipt_batch_applied']);
 }
 public function test_preparer_cannot_apply_own_batch():void {
  $f=$this->fixture();$b=$this->preview($f);$this->actingAs($f['m'])->post($this->url($b,'review'),['version'=>1,'decision'=>'apply','note'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('receipts');$this->assertDatabaseCount('alert_receipt_records',0);
 }
 public function test_identical_preview_request_deduplicates_but_changed_reuse_refuses():void {
  $f=$this->fixture();$id=(string)Str::uuid();$csv=$this->csv([$this->row($f['d'])]);$a=Ops::preview($f['m'],1,$id,$csv,'Fictional source');$b=Ops::preview($f['m'],1,$id,$csv,'Fictional source');$this->assertSame($a->id,$b->id);$this->actingAs($f['m'])->post('/receipt-operations',['request_token'=>$id,'csv'=>$csv,'source_reference'=>'Changed','confirmed'=>1])->assertSessionHasErrors('receipts');$this->assertDatabaseCount('alert_receipt_batches',1);
 }
 public function test_duplicate_active_provider_event_cannot_be_imported_twice():void {
  $f=$this->fixture();$this->applied($f);$this->actingAs($f['m'])->post('/receipt-operations',['request_token'=>(string)Str::uuid(),'csv'=>$this->csv([$this->row($f['d'])]),'source_reference'=>'Fictional second copy','confirmed'=>1])->assertSessionHasErrors('receipts');$this->assertDatabaseCount('alert_receipt_records',1);
 }
 public function test_header_order_extra_fields_blank_rows_and_duplicate_delivery_are_rejected():void {
  $f=$this->fixture();$csv=$this->csv([$this->row($f['d'])]);$bad=[str_replace('delivery_id,channel','channel,delivery_id',$csv),str_replace("\n","\n\n",$csv),$this->csv([$this->row($f['d']),$this->row($f['d'])]),str_replace('source_reference','source_reference,extra',$csv)];$this->actingAs($f['m']);foreach($bad as$text){$this->post('/receipt-operations',['request_token'=>(string)Str::uuid(),'csv'=>$text,'source_reference'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('receipts');}$this->assertDatabaseCount('alert_receipt_batches',0);
 }
 public function test_quoted_commas_quotes_and_utf8_bom_round_trip():void {
  $f=$this->fixture();$r=$this->row($f['d']);$r['source_reference']='Fictional "checked", source 🚙';$rows=Ops::parse("\xEF\xBB\xBF".$this->csv([$r]));$this->assertSame($r,$rows[0]);
 }
 public function test_unclosed_or_illegal_csv_quotes_are_not_silently_accepted():void {
  $f=$this->fixture();$csv=$this->csv([$this->row($f['d'])]);$this->actingAs($f['m']);foreach([rtrim($csv).',"unclosed',str_replace('PRIVATE-PROVIDER-SOURCE','"unclosed',$csv),str_replace('PRIVATE-PROVIDER-SOURCE','un"quoted',$csv)]as$bad){$this->post('/receipt-operations',['request_token'=>(string)Str::uuid(),'csv'=>$bad,'source_reference'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('receipts');}
 }
 public function test_250_row_parse_limit_and_invalid_utf8_nul_byte_limits_are_enforced():void {
  $f=$this->fixture();$rows=[];for($i=0;$i<250;$i++){$r=$this->row($f['d']);$r['delivery_id']=(string)Str::uuid();$rows[]=$r;}$this->assertCount(250,Ops::parse($this->csv($rows)));$rows[]=$this->row($f['d']);$this->actingAs($f['m']);foreach([$this->csv($rows),"\xFF","a\0b",str_repeat('x',250001)]as$bad){$this->post('/receipt-operations',['request_token'=>(string)Str::uuid(),'csv'=>$bad,'source_reference'=>'Fictional','confirmed'=>1])->assertSessionHasErrors();}$this->assertDatabaseCount('alert_receipt_batches',0);
 }
 public function test_unknown_delivery_wrong_provider_and_channel_fail_closed():void {
  $f=$this->fixture();$this->actingAs($f['m']);foreach([['delivery_id',(string)Str::uuid()],['provider_id','wrong-provider'],['channel','email']]as[$key,$value]){$r=$this->row($f['d']);$r[$key]=$value;$this->post('/receipt-operations',['request_token'=>(string)Str::uuid(),'csv'=>$this->csv([$r]),'source_reference'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('receipts');}$this->assertDatabaseCount('alert_receipt_batches',0);
 }
 public function test_uncertain_unreserved_and_prepared_attempts_cannot_be_labelled_delivered():void {
  $f=$this->fixture();$this->actingAs($f['m']);foreach([['state','Uncertain'],['state','Prepared'],['reserved_at',null],['provider_id',null]]as[$key,$value]){$original=$f['d']->$key;$f['d']->$key=$value;$f['d']->save();$r=$this->row($f['d']);$r['provider_id']=$r['provider_id']??'FICTIONAL';$this->post('/receipt-operations',['request_token'=>(string)Str::uuid(),'csv'=>$this->csv([$r]),'source_reference'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('receipts');$f['d']->$key=$original;$f['d']->save();}
 }
 public function test_future_before_reservation_and_bad_timestamp_are_refused():void {
  $f=$this->fixture();$this->actingAs($f['m']);foreach([now()->addDay()->format('Y-m-d H:i:s'),now()->subHour()->format('Y-m-d H:i:s'),'2026-02-30 11:00:00']as$time){$this->post('/receipt-operations',['request_token'=>(string)Str::uuid(),'csv'=>$this->csv([$this->row($f['d'],'Delivered',$time)]),'source_reference'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('receipts');}
 }
 public function test_bounced_sms_and_mismatched_email_evidence_kind_are_refused():void {
  $f=$this->fixture();$this->actingAs($f['m']);$r=$this->row($f['d'],'Bounced');$this->post('/receipt-operations',['request_token'=>(string)Str::uuid(),'csv'=>$this->csv([$r]),'source_reference'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('receipts');$r=$this->row($f['d']);$r['evidence_kind']='email_delivery_report';$this->post('/receipt-operations',['request_token'=>(string)Str::uuid(),'csv'=>$this->csv([$r]),'source_reference'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('receipts');
 }
 public function test_email_bounce_matches_exact_stored_smtp_hash_and_opens_followup():void {
  $f=$this->fixture('email');$this->applied($f,'Bounced');$p=Ops::position($f['d']);$this->assertSame('Bounced',$p['outcome']);$this->assertSame('Open',$p['case']->state);$this->assertSame('Accepted',$f['d']->fresh()->state);
 }
 public function test_preview_expiry_exact_boundary_and_stale_batch_version_refuse_apply():void {
  $f=$this->fixture();$b=$this->preview($f);$this->actingAs($f['n'])->post($this->url($b,'review'),['version'=>0,'decision'=>'apply','note'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('version');$this->travel(24)->hours();$this->post($this->url($b,'review'),['version'=>1,'decision'=>'apply','note'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('receipts');$this->assertDatabaseCount('alert_receipt_records',0);
 }
 public function test_portal_seen_and_investigation_note_do_not_stale_transport_evidence():void {
  $f=$this->fixture();$b=$this->preview($f);$f['d']->seen_at=now();$f['d']->reconciliation=[['note'=>'Fictional investigation']];$f['d']->save();Ops::review($f['n'],1,$b,1,'apply','Fictional');$this->assertSame('Delivered',Ops::position($f['d']->fresh())['outcome']);$this->assertNotNull($f['d']->fresh()->seen_at);
 }
 public function test_changed_transport_binding_or_existing_evidence_stales_entire_preview():void {
  $f=$this->fixture();$b=$this->preview($f);$f['d']->provider_id='CHANGED';$f['d']->save();$this->actingAs($f['n'])->post($this->url($b,'review'),['version'=>1,'decision'=>'apply','note'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('receipts');$this->assertSame('Preview',$b->fresh()->state);$this->assertDatabaseCount('alert_receipt_records',0);
 }
 public function test_multirow_batch_is_atomic_when_one_attempt_changes():void {
  $f=$this->fixture();$g=$this->fixture('email');$b=Ops::preview($f['m'],1,(string)Str::uuid(),$this->csv([$this->row($f['d']),$this->row($g['d'])]),'Fictional');$g['d']->state='Uncertain';$g['d']->save();$this->actingAs($f['n'])->post($this->url($b,'review'),['version'=>1,'decision'=>'apply','note'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('receipts');$this->assertDatabaseCount('alert_receipt_records',0);$this->assertSame('Preview',$b->fresh()->state);
 }
 public function test_required_audit_failure_rolls_back_batch_receipts_and_followup_cases():void {
  $f=$this->fixture();$b=$this->preview($f,'Failed');AuditFault::install('receipt_fixture_fault','alert_receipt_events','Fictional forced audit failure','receipt_batch_applied');try{Ops::review($f['n'],1,$b,1,'apply','Fictional');$this->fail('audit ignored');}catch(\Illuminate\Database\QueryException){$this->assertDatabaseCount('alert_receipt_records',0);$this->assertDatabaseCount('alert_followup_cases',0);$this->assertSame('Preview',$b->fresh()->state);}
 }
 public function test_older_intermediate_receipt_does_not_downgrade_terminal_delivery():void {
  $f=$this->fixture();$this->applied($f,'Delivered',now()->subMinute()->format('Y-m-d H:i:s'));$this->applied($f,'Deferred',now()->subMinutes(3)->format('Y-m-d H:i:s'));$this->assertSame('Delivered',Ops::position($f['d'])['outcome']);
 }
 public function test_conflicting_terminal_reports_are_not_automatically_resolved_by_latest_time():void {
  $f=$this->fixture();$this->applied($f,'Failed',now()->subMinutes(3)->format('Y-m-d H:i:s'));$this->applied($f,'Delivered',now()->subMinute()->format('Y-m-d H:i:s'));$p=Ops::position($f['d']);$this->assertSame('Conflict',$p['outcome']);$this->assertSame('Open',$p['case']->state);$this->assertFalse($p['resolved']);
 }
 public function test_case_owner_date_note_are_versioned_and_send_nothing():void {
  $f=$this->fixture();$before=$f['d']->fresh()->getAttributes();$c=Ops::saveCase($f['m'],1,$f['d'],0,$f['n']->id,today()->addDay()->toDateString(),'PRIVATE-FOLLOWUP-NOTE');$this->assertSame($f['n']->id,$c->owner_id);$this->assertSame($before,$f['d']->fresh()->getAttributes());$raw=DB::table('alert_receipt_events')->where('event','followup_saved')->value('private_note');$this->assertStringNotContainsString('PRIVATE',$raw);$this->actingAs($f['m'])->get('/receipt-deliveries/'.$f['d']->id)->assertOk()->assertSee('PRIVATE-FOLLOWUP-NOTE');
 }
 public function test_inactive_wrong_role_owner_bad_date_and_stale_case_refuse():void {
  $f=$this->fixture();$t=User::factory()->create(['role'=>'tutor']);$this->actingAs($f['m']);$url='/receipt-deliveries/'.$f['d']->id.'/case';$base=['version'=>0,'note'=>'Fictional','confirmed'=>1];$this->post($url,[...$base,'owner_id'=>$t->id])->assertSessionHasErrors('receipts');$this->post($url,[...$base,'due_on'=>today()->subDay()->toDateString()])->assertSessionHasErrors('due_on');$this->post($url,[...$base,'version'=>1])->assertSessionHasErrors('receipts');$this->assertDatabaseCount('alert_followup_cases',0);
 }
 public function test_conflict_closure_requires_exact_existing_record_and_independent_check():void {
  $f=$this->fixture();$this->applied($f,'Failed',now()->subMinutes(3)->format('Y-m-d H:i:s'));$this->applied($f,'Delivered');$p=Ops::position($f['d']);$c=$p['case'];$this->actingAs($f['m'])->post($this->caseUrl($c,'propose'),['version'=>$c->version,'note'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('receipts');$record=Ops::records($f['d'])->firstWhere('outcome','Delivered');Ops::proposeClose($f['m'],1,$c,$c->version,$record->id,'PRIVATE-CONFLICT-REASON');$c->refresh();$this->post($this->caseUrl($c,'check'),['version'=>$c->version,'decision'=>'close','note'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('receipts');Ops::checkClose($f['n'],1,$c,$c->version,'close','PRIVATE-SECOND-CHECK');$p=Ops::position($f['d']);$this->assertSame('Delivered',$p['outcome']);$this->assertTrue($p['resolved']);$this->assertSame('Closed',$p['case']->state);
 }
 public function test_foreign_withdrawn_or_fabricated_receipt_cannot_be_selected():void {
  $f=$this->fixture();$g=$this->fixture();$this->applied($f,'Failed');$this->applied($g);$c=Ops::position($f['d'])['case'];$r=Ops::records($g['d'])->first();$this->actingAs($f['m'])->post($this->caseUrl($c,'propose'),['version'=>$c->version,'record_id'=>$r->id,'note'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('receipts');
 }
 public function test_new_evidence_reopens_closed_case_and_invalidates_old_resolution():void {
  $f=$this->fixture();$this->applied($f,'Failed');$c=Ops::position($f['d'])['case'];Ops::proposeClose($f['m'],1,$c,$c->version,null,'Fictional action completed');$c->refresh();Ops::checkClose($f['n'],1,$c,$c->version,'close','Fictional check');$this->applied($f,'Delivered',now()->subMinute()->format('Y-m-d H:i:s'));$p=Ops::position($f['d']);$this->assertSame('Conflict',$p['outcome']);$this->assertSame('Open',$p['case']->state);$this->assertNull($p['case']->resolution);
 }
 public function test_reject_or_withdraw_preview_leaves_existing_receipts_and_cases_unchanged():void {
  $f=$this->fixture();$this->applied($f,'Failed');$c=Ops::position($f['d'])['case'];$before=$c->getAttributes();$b=$this->preview($f,'Deferred');Ops::withdraw($f['m'],1,$b,1,'Fictional cancel');$this->assertSame($before,$c->fresh()->getAttributes());$b=$this->preview($f,'Delivered');Ops::review($f['n'],1,$b,1,'reject','Fictional reject');$this->assertSame($before,$c->fresh()->getAttributes());$this->assertDatabaseCount('alert_receipt_records',1);
 }
 public function test_applied_withdrawal_retains_history_and_allows_corrected_reimport_without_retry():void {
  $f=$this->fixture();$b=$this->applied($f);$before=$f['d']->fresh()->getAttributes();Ops::withdraw($f['n'],1,$b,2,'Fictional correction');$this->assertSame('No receipt',Ops::position($f['d'])['outcome']);$this->assertNotNull(Receipt::first()->withdrawn_at);$this->assertNull(Receipt::first()->slot_key);$this->applied($f);$this->assertDatabaseCount('alert_receipt_records',2);$this->assertSame('Delivered',Ops::position($f['d'])['outcome']);$this->assertSame($before,$f['d']->fresh()->getAttributes());
 }
 public function test_applied_batch_preparer_cannot_withdraw_own_checked_evidence():void {
  $f=$this->fixture();$b=$this->applied($f);$this->actingAs($f['m'])->post($this->url($b,'withdraw'),['version'=>2,'note'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('receipts');$this->assertSame('Applied',$b->fresh()->state);
 }
 public function test_closure_rechecks_attempt_records_preparer_authority_and_versions():void {
  $f=$this->fixture();$this->applied($f,'Failed');$c=Ops::position($f['d'])['case'];Ops::proposeClose($f['m'],1,$c,$c->version,null,'Fictional');$c->refresh();$f['m']->auth_version++;$f['m']->save();$this->actingAs($f['n'])->post($this->caseUrl($c,'check'),['version'=>$c->version,'decision'=>'close','note'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('receipts');$this->assertSame('Awaiting check',$c->fresh()->state);
 }
 public function test_returned_case_is_open_and_does_not_change_provider_outcome():void {
  $f=$this->fixture();$this->applied($f,'Failed');$c=Ops::position($f['d'])['case'];Ops::proposeClose($f['m'],1,$c,$c->version,null,'Fictional');$c->refresh();Ops::checkClose($f['n'],1,$c,$c->version,'return','Need more actual evidence');$this->assertSame('Open',$c->fresh()->state);$this->assertSame('Failed',Ops::position($f['d'])['outcome']);
 }
 public function test_corrupt_batch_or_receipt_metadata_fails_closed_without_exposing_private_values():void {
  $f=$this->fixture();$b=$this->applied($f);$r=Receipt::first();DB::table('alert_receipt_records')->where('id',$r->id)->update(['outcome'=>'Failed']);$this->assertSame('Needs recheck',Ops::position($f['d'])['outcome']);DB::table('alert_receipt_batches')->where('id',$b->id)->update(['rows'=>'CORRUPT PRIVATE']);$this->actingAs($f['n'])->get($this->url($b))->assertStatus(409)->assertDontSee('CORRUPT PRIVATE');$this->assertSame('Needs recheck',Ops::position($f['d'])['outcome']);
 }
 public function test_only_active_admin_ceo_can_read_write_template_or_export():void {
  $f=$this->fixture();$b=$this->preview($f);foreach(['tutor','apprentice','employer','careers_adviser','inclusion_lead','staff']as$role){$u=User::factory()->create(['role'=>$role]);$this->actingAs($u);foreach(['/receipt-operations','/receipt-operations/template.csv','/receipt-operations/report.csv',$this->url($b),'/receipt-deliveries/'.$f['d']->id]as$path){$this->get($path)->assertForbidden();}$this->post('/receipt-operations',[])->assertForbidden();}$this->assertDatabaseCount('alert_receipt_records',0);
 }
 public function test_stale_actor_access_version_refuses_batch_application():void {
  $f=$this->fixture();$b=$this->preview($f);$f['n']->auth_version=2;$f['n']->save();try{Ops::review($f['n'],1,$b,1,'apply','Fictional');$this->fail('stale accepted');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(403,$e->getStatusCode());}$this->assertDatabaseCount('alert_receipt_records',0);
 }
 public function test_worklist_channel_date_case_overdue_and_search_filters_are_private():void {
  $f=$this->fixture();$this->applied($f,'Failed');$c=Ops::position($f['d'])['case'];Ops::saveCase($f['m'],1,$f['d'],$c->version,$f['m']->id,today()->toDateString(),'Fictional');$this->travel(1)->days();$this->actingAs($f['m'])->get('/receipt-operations?show=overdue&search='.$f['a']->reference)->assertOk()->assertSee($f['d']->id)->assertDontSee('PRIVATE-PROVIDER-SOURCE');$this->get('/receipt-operations?channel=email')->assertDontSee($f['d']->id);$this->get('/receipt-operations?show=wrong')->assertSessionHasErrors('show');$this->get('/receipt-operations?from=2026-02-03&to=2026-02-01')->assertSessionHasErrors('to');
 }
 public function test_operations_csv_has_no_contacts_private_refs_or_message_text():void {
  $f=$this->fixture();$f['a']->reference='=FICTIONAL';$f['a']->save();$this->applied($f);$this->actingAs($f['m']);$r=$this->get('/receipt-operations/report.csv')->assertOk()->assertHeader('Cache-Control','no-store, private');$csv=$r->streamedContent();$this->assertStringContainsString("'=FICTIONAL",$csv);foreach(['PRIVATE-PROVIDER','PRIVATE-TASK',$f['u']->email]as$value){$this->assertStringNotContainsString($value,$csv);}$this->assertStringContainsString('Delivered',$csv);$this->assertStringContainsString('Accepted',$csv);
 }
 public function test_read_only_get_does_not_open_cases_apply_batches_or_send():void {
  $f=$this->fixture();$b=$this->preview($f);$this->actingAs($f['m'])->get('/receipt-operations')->assertOk();$this->get($this->url($b))->assertOk();$this->get('/receipt-deliveries/'.$f['d']->id)->assertOk();$this->assertDatabaseCount('alert_receipt_records',0);$this->assertDatabaseCount('alert_followup_cases',0);$this->assertSame('Preview',$b->fresh()->state);
 }
 public function test_confirmation_and_post_only_mutations_are_required():void {
  $f=$this->fixture();$b=$this->preview($f);$this->actingAs($f['n'])->post($this->url($b,'review'),['version'=>1,'decision'=>'apply','note'=>'Fictional'])->assertSessionHasErrors('confirmed');foreach(['review','withdraw']as$action){$this->get($this->url($b,$action))->assertStatus(405);}$this->get('/apprentice-reminder-delivery')->assertOk()->assertSee('Provider delivery outcomes and failure follow-up');
 }
 public function test_reopened_resolved_conflict_requires_fresh_selection_and_independent_check():void {
  $f=$this->fixture();$this->applied($f,'Failed',now()->subMinutes(3)->format('Y-m-d H:i:s'));$this->applied($f,'Delivered');$c=Ops::position($f['d'])['case'];$r=Ops::records($f['d'])->firstWhere('outcome','Delivered');Ops::proposeClose($f['m'],1,$c,$c->version,$r->id,'Fictional checked source');$c->refresh();Ops::checkClose($f['n'],1,$c,$c->version,'close','Fictional independent check');$this->assertSame('Delivered',Ops::position($f['d'])['outcome']);$c->refresh();Ops::saveCase($f['m'],1,$f['d'],$c->version,$f['m']->id,today()->toDateString(),'Fictional reopened investigation');$c->refresh();$this->assertNull($c->resolution);$this->assertSame('Conflict',Ops::position($f['d'])['outcome']);$this->actingAs($f['m'])->post($this->caseUrl($c,'propose'),['version'=>$c->version,'note'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('receipts');
 }
 public function test_multiple_batches_with_same_baseline_only_first_can_apply():void {
  $f=$this->fixture();$a=$this->preview($f,'Deferred');$b=$this->preview($f,'Delivered');Ops::review($f['n'],1,$a,1,'apply','Fictional');$this->actingAs($f['n'])->post($this->url($b,'review'),['version'=>1,'decision'=>'apply','note'=>'Fictional','confirmed'=>1])->assertSessionHasErrors('receipts');$this->assertSame('Preview',$b->fresh()->state);$this->assertDatabaseCount('alert_receipt_records',1);
 }
}
