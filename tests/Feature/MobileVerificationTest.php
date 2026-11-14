<?php
namespace Tests\Feature;
use App\Models\{Apprentice,ApprenticeMobileChallenge as Challenge,User};
use App\Support\MobileVerification;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB,Http};
use Tests\Concerns\ConfiguresStaging;
use Tests\TestCase;
class MobileVerificationTest extends TestCase {
 use DatabaseMigrations,ConfiguresStaging;
 private string $code='';
 private $providerResponse=null;
 private int $providerAttempts=0;
 private function fixture():array {
  $this->configureStaging();config(['mobile_verification.enabled'=>true,'mobile_verification.recipients'=>['+447700900123'],'mobile_verification.key'=>'FAKEKEY1','mobile_verification.secret'=>'FICTIONAL-SECRET','mobile_verification.from'=>'GTA']);
  $u=User::factory()->create(['role'=>'apprentice']);$a=Apprentice::factory()->create(['user_id'=>$u->id]);$a->alert_channel='both';$a->alerts_enabled=true;$a->alert_mobile='+447700900123';$a->save();
  Http::preventStrayRequests();$this->accepted();$this->actingAs($u);return [$u,$a];
 }
 private function accepted():void {Http::fake([MobileVerification::ENDPOINT=>function($request){$this->providerAttempts++;preg_match('/code: ([0-9]{8})/',$request['text'],$m);$this->code=$m[1];if($this->providerResponse instanceof \Throwable){throw $this->providerResponse;}if($this->providerResponse!==null){return $this->providerResponse;}return Http::response(['message-count'=>'1','messages'=>[['status'=>'0','message-id'=>'FICTIONAL-ID','to'=>'447700900123']]],200);}]);}
 private function requestCode(){return $this->withSession(['_token'=>'fictional-csrf'])->withCookie(config('session.cookie'),session()->getId())->post('https://staging.example.test/account/alerts/mobile/request',['_token'=>'fictional-csrf']);}
 private function confirmCode(?string $code=null,?string $id=null){return $this->withCookie(config('session.cookie'),session()->getId())->post('https://staging.example.test/account/alerts/mobile/confirm',['_token'=>'fictional-csrf','challenge_id'=>$id??Challenge::latest('created_at')->firstOrFail()->id,'code'=>$code??$this->code]);}
 public function test_code_is_hashed_bound_and_single_use_with_preference_choice_preserved():void {
  [$u,$a]=$this->fixture();$this->requestCode()->assertRedirect()->assertSessionHasNoErrors();$c=Challenge::firstOrFail();$this->assertSame('Accepted',$c->state);$this->assertNotSame($this->code,$c->code_hash);$this->assertStringNotContainsString($this->code,json_encode($c));$this->assertStringNotContainsString('447700900123',json_encode($c));$this->assertNull($a->fresh()->alert_mobile_verified_at);
  $this->confirmCode()->assertSessionHasNoErrors();$a->refresh();$this->assertNotNull($a->alert_mobile_verified_at);$this->assertTrue($a->alerts_enabled);$this->assertSame('both',$a->alert_channel);$this->assertSame('Consumed',$c->fresh()->state);$this->assertNull($c->fresh()->code_hash);$this->confirmCode()->assertSessionHasErrors('mobile_code');Http::assertSentCount(1);
 }
 public function test_disabled_production_and_off_preferences_never_send():void {
  [$u,$a]=$this->fixture();config(['mobile_verification.enabled'=>false]);$this->requestCode()->assertSessionHasErrors('mobile_code');config(['mobile_verification.enabled'=>true]);$this->app['env']='production';$this->requestCode()->assertStatus(503);$this->app['env']='staging';$a->alerts_enabled=false;$a->save();$this->requestCode()->assertSessionHasErrors('mobile_code');Http::assertNothingSent();$this->assertDatabaseCount('apprentice_mobile_challenges',0);
 }
 public function test_non_apprentice_unlinked_and_unapproved_number_are_refused():void {
  [$u,$a]=$this->fixture();$admin=User::factory()->create(['role'=>'admin']);$this->actingAs($admin);$this->requestCode()->assertForbidden();$this->actingAs($u);config(['mobile_verification.recipients'=>['+447700900124']]);$this->requestCode()->assertSessionHasErrors('mobile_code');config(['mobile_verification.recipients'=>['+447700900123']]);$a->user_id=null;$a->save();$this->requestCode()->assertSessionHasErrors('mobile_code');Http::assertNothingSent();
 }
 public function test_five_wrong_attempts_commit_lockout_and_do_not_flash_the_code():void {
  $this->fixture();$this->requestCode();for($i=0;$i<5;$i++){$this->confirmCode('00000000')->assertSessionHasErrors('mobile_code')->assertSessionMissing('_old_input.code');}$c=Challenge::firstOrFail();$this->assertSame(5,$c->attempts);$this->assertSame('Locked',$c->state);$this->assertNull($c->code_hash);$this->confirmCode()->assertSessionHasErrors('mobile_code');Http::assertSentCount(1);
 }
 public function test_expiry_and_saved_preference_changes_invalidate_pending_code():void {
  [$u,$a]=$this->fixture();$this->requestCode();$this->travel(10)->minutes();$this->confirmCode()->assertSessionHasErrors('mobile_code');$this->assertNull($a->fresh()->alert_mobile_verified_at);$this->assertSame('Superseded',Challenge::first()->state);
  $this->requestCode()->assertSessionHasNoErrors();$a->alert_revision++;$a->save();$this->confirmCode()->assertSessionHasErrors('mobile_code');$this->assertNull($a->fresh()->alert_mobile_verified_at);
 }
 public function test_resend_throttle_new_code_and_daily_request_budget():void {
  $this->fixture();$this->requestCode();$old=Challenge::first();$this->requestCode()->assertSessionHasErrors('mobile_code');Http::assertSentCount(1);
  for($i=0;$i<4;$i++){$this->travel(61)->seconds();$this->requestCode()->assertSessionHasNoErrors();}$this->assertSame('Superseded',$old->fresh()->state);$this->travel(61)->seconds();$this->requestCode()->assertSessionHasErrors('mobile_code');Http::assertSentCount(5);$this->assertDatabaseCount('apprentice_mobile_challenges',5);
 }
 public function test_rejected_and_uncertain_provider_outcomes_keep_reservation_budget_and_no_automatic_retry():void {
  $this->fixture();$this->providerResponse=Http::response(['message-count'=>'1','messages'=>[['status'=>'4','error-text'=>'SECRET-ERROR']]],200);$this->requestCode()->assertRedirect();$this->assertSame('Rejected',Challenge::first()->state);$this->assertNull(Challenge::first()->code_hash);$this->travel(61)->seconds();$this->providerResponse=Http::response([],503);$this->requestCode()->assertRedirect();$this->assertSame('Uncertain',Challenge::latest('created_at')->first()->state);$this->assertDatabaseCount('apprentice_mobile_challenges',2);Http::assertSentCount(2);
 }
 public function test_provider_redirect_malformed_or_wrong_recipient_never_verifies_or_becomes_accepted():void {
  [$u,$a]=$this->fixture();foreach([Http::response('',302,['Location'=>'https://other.example.test']),Http::response('not-json',200),Http::response(['message-count'=>'1','messages'=>[['status'=>'0','message-id'=>'FAKE','to'=>'447700900999']]],200)]as$response){$this->providerResponse=$response;$this->requestCode();$this->assertSame('Uncertain',Challenge::latest('created_at')->first()->state);$this->travel(61)->seconds();}$this->assertNull($a->fresh()->alert_mobile_verified_at);
 }
 public function test_api_request_uses_fixed_tls_endpoint_basic_auth_generic_text_and_no_sensitive_query():void {
  $this->fixture();$this->requestCode();Http::assertSent(fn($r)=>$r->url()===MobileVerification::ENDPOINT&&$r->hasHeader('Authorization','Basic '.base64_encode('FAKEKEY1:FICTIONAL-SECRET'))&&$r['to']==='447700900123'&&!str_contains($r->url(),'SECRET')&&str_contains($r['text'],'Expires in 10 minutes'));
 }
 public function test_confirm_audit_failure_rolls_back_profile_and_challenge_without_resending():void {
  [$u,$a]=$this->fixture();$this->requestCode();\Tests\Support\AuditFault::install('fail_mobile_confirmation','audit_events','Fictional audit failure');$this->confirmCode()->assertStatus(500);$this->assertNull($a->fresh()->alert_mobile_verified_at);$this->assertSame('Accepted',Challenge::first()->state);Http::assertSentCount(1);
 }
 public function test_finalisation_failure_retains_committed_reservation_for_uncertainty_review():void {
  $this->fixture();Challenge::updating(function($c){if($c->state==='Accepted'){throw new \RuntimeException('Fictional finalisation failure');}});try{$this->requestCode()->assertStatus(500);$this->assertSame('Reserved',Challenge::first()->state);$this->requestCode()->assertSessionHasErrors('mobile_code');Http::assertSentCount(1);}finally{Challenge::flushEventListeners();}
 }
 public function test_code_cannot_be_reused_in_another_signed_in_session():void {
  [$u,$a]=$this->fixture();$this->requestCode();session()->migrate(true);$this->withSession(['auth_version'=>$u->auth_version,'_token'=>'fictional-csrf']);$this->confirmCode()->assertSessionHasErrors('mobile_code');$this->assertNull($a->fresh()->alert_mobile_verified_at);$this->assertSame(0,Challenge::first()->attempts);
 }
 public function test_foreign_apprentice_cannot_consume_or_lock_another_challenge():void {
  [$u,$a]=$this->fixture();$this->requestCode();$other=User::factory()->create(['role'=>'apprentice']);$b=Apprentice::factory()->create(['user_id'=>$other->id]);$b->alert_channel='sms';$b->alerts_enabled=true;$b->alert_mobile='+447700900123';$b->save();$this->actingAs($other);$this->confirmCode()->assertSessionHasErrors('mobile_code');$this->assertSame('Accepted',Challenge::first()->state);$this->assertSame(0,Challenge::first()->attempts);$this->assertNull($b->fresh()->alert_mobile_verified_at);
 }
 public function test_transport_exception_stays_uncertain_and_configuration_change_invalidates_old_code():void {
  [$u,$a]=$this->fixture();$this->providerResponse=new \Illuminate\Http\Client\ConnectionException('FICTIONAL SECRET transport failure');$this->requestCode()->assertRedirect();$this->assertSame('Uncertain',Challenge::first()->state);$this->travel(61)->seconds();$this->providerResponse=null;$this->requestCode();config(['mobile_verification.secret'=>'CHANGED-FICTIONAL-SECRET']);$this->confirmCode()->assertSessionHasErrors('mobile_code');$this->assertNull($a->fresh()->alert_mobile_verified_at);$this->assertSame('Superseded',Challenge::latest('created_at')->first()->state);$this->assertSame(2,$this->providerAttempts);
 }
 public function test_missing_csrf_does_not_reserve_or_send():void {
  $this->fixture();$this->withSession(['_token'=>'fictional-csrf'])->withCookie(config('session.cookie'),session()->getId())->post('https://staging.example.test/account/alerts/mobile/request')->assertStatus(419);$this->assertDatabaseCount('apprentice_mobile_challenges',0);Http::assertNothingSent();
 }
 public function test_configuration_diagnostic_does_not_connect_or_print_secrets_and_history_is_aggregate_only():void {
  $this->fixture();$info=MobileVerification::inspect();$this->assertSame('configuration_checks_only',$info['result']);$this->assertStringNotContainsString('FICTIONAL-SECRET',json_encode($info));$this->assertStringNotContainsString('447700900123',json_encode($info));Http::assertNothingSent();$this->requestCode();$before=Challenge::first()->getAttributes();$history=MobileVerification::history();$this->assertSame(1,$history['states']['Accepted']);$this->assertSame($before,Challenge::first()->getAttributes());$this->assertStringNotContainsString($this->code,json_encode($history));
 }
}
