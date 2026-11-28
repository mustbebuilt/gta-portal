<?php
namespace Tests\Feature;
use App\Models\{Apprentice,Programme,User};
use App\Notifications\AccountOnboardingLink;
use App\Support\{AccountAccess,AccountOnboarding,ApprenticeOnboarding as StartUp};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\{Crypt,DB,Hash,Notification,RateLimiter,Schema};
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Concerns\ConfiguresStaging;
use Tests\TestCase;

class ApprenticeOnboardingTest extends TestCase
{
 use LazilyRefreshDatabase,ConfiguresStaging;
 private function fixture(bool $linked=false):array {
  $m=User::factory()->create(['role'=>'admin']);$u=User::factory()->unverified()->create(['role'=>'apprentice','email'=>'learner@example.test']);
  $a=Apprentice::factory()->create(['user_id'=>$linked?$u->id:null,'email'=>$u->email]);return [$m,$u,$a];
 }
 private function data(Apprentice $a,string $mode='new',?User $u=null):array {
  $d=['version'=>$a->version,'mode'=>$mode,'identity_reference'=>'FICTIONAL-PRIVATE-IDENTITY-REFERENCE','confirmed'=>'1'];if($mode==='existing'){$d+=['existing_user_id'=>$u->id,'record_version'=>$u->record_version];}return $d;
 }
 private function linkUrl(Apprentice $a):string{return '/apprentice-onboarding/'.$a->id.'/link';}
 private function pilot(User $u):void {
  $this->configureStaging();config(['onboarding.enabled'=>true,'onboarding.recipients'=>[$u->email],'mail.default'=>'smtp','mail.mailers.smtp'=>['transport'=>'smtp','scheme'=>'smtps','url'=>null,'host'=>'smtp.example.test','port'=>465,'timeout'=>10],'mail.from.address'=>'portal@example.test']);
 }
 private function issue(User $u,User $m):string {
  Notification::fake();DB::transaction(fn()=>AccountOnboarding::issue($u,'learner_invite',$m));return parse_url(Notification::sent($u,AccountOnboardingLink::class)->last()->link,PHP_URL_PATH);
 }
 private function postPilot(string $path,array $data=[]) {return $this->withSession(['_token'=>'fictional-csrf'])->post('https://staging.example.test'.$path,[...$data,'_token'=>'fictional-csrf']);}
 private function password():array{return ['password'=>'Fictional-apprentice-passphrase-28!','password_confirmation'=>'Fictional-apprentice-passphrase-28!'];}
 public function test_apprentice_record_links_authorised_staff_to_password_help():void {
  [$admin,$login,$apprentice]=$this->fixture(false);
  $tutor=User::factory()->create(['role'=>'tutor']);
  $apprentice->update(['tutor_id'=>$tutor->id]);
  $record=route('apprentices.show',$apprentice);
  $help=route('apprentice-onboarding.show',$apprentice);
  $this->actingAs($admin)->get($record)->assertOk()->assertSee('Apprentice login and password help')->assertSee($help);
  $this->get($help)->assertOk()->assertSee('Set up first-time access');
  $this->actingAs($tutor)->get($record)->assertOk()->assertDontSee('Apprentice login and password help');
  $this->get($help)->assertForbidden();

  $apprentice->forceFill(['user_id'=>$login->id])->save();
  $this->actingAs($admin)->get($help)->assertOk()->assertSee('Login invitation unavailable');
  $login->forceFill(['email_verified_at'=>now()])->save();
  config(['account_access.recovery_enabled'=>true,'mail.default'=>'array']);
  $this->get($help)->assertOk()->assertSee('Forgotten your password?')->assertDontSee('Send login invitation');
  $login->forceFill(['active'=>false])->save();
  $this->get($help)->assertOk()->assertSee('Check the account link');
 }
 public function test_create_login_requires_identity_confirmation_and_separate_invitation():void {
  [$m,$u,$a]=$this->fixture();$u->delete();Notification::fake();$before=$a->fresh()->getAttributes();$this->actingAs($m)->post($this->linkUrl($a),$this->data($a))->assertSessionHasNoErrors();
  $a->refresh();$account=User::findOrFail($a->user_id);$this->assertSame('apprentice',$account->role);$this->assertNull($account->email_verified_at);$this->assertTrue($account->active);$this->assertSame($a->email,$account->email);$this->assertSame(2,$a->version);$this->assertDatabaseCount('apprentice_onboarding_events',1);Notification::assertNothingSent();$this->assertDatabaseCount('account_onboarding_tokens',0);
  foreach(['programme_id','tutor_id','employer_id','alerts_enabled','alert_channel','alert_revision','start_date','planned_end_date']as$key){$this->assertSame($before[$key]??null,$a->getAttributes()[$key]??null);}
  $raw=DB::table('apprentice_onboarding_events')->value('identity_reference');$this->assertStringNotContainsString('FICTIONAL-PRIVATE',$raw);$this->assertSame('FICTIONAL-PRIVATE-IDENTITY-REFERENCE',Crypt::decryptString($raw));
 }
 public function test_existing_login_is_explicit_and_invalidates_prior_sessions_without_changing_role_or_password():void {
  [$m,$u,$a]=$this->fixture();$hash=$u->password;$this->actingAs($m)->post($this->linkUrl($a),$this->data($a,'existing',$u))->assertSessionHasNoErrors();$this->assertSame($u->id,$a->fresh()->user_id);$this->assertSame(2,$u->fresh()->auth_version);$this->assertSame($hash,$u->fresh()->password);$this->assertSame('apprentice',$u->fresh()->role);
 }
 public function test_duplicate_email_is_not_auto_merged_or_role_converted():void {
  [$m,$u,$a]=$this->fixture();$this->actingAs($m)->post($this->linkUrl($a),$this->data($a))->assertSessionHasErrors('onboarding');$this->assertNull($a->fresh()->user_id);$this->assertDatabaseCount('apprentice_onboarding_events',0);
  foreach(['admin','ceo','employer','tutor']as$role){$u->role=$role;$u->save();$this->post($this->linkUrl($a),$this->data($a,'existing',$u))->assertSessionHasErrors('onboarding');$this->assertSame($role,$u->fresh()->role);}
 }
 public function test_wrong_inactive_stale_account_and_already_used_login_are_rejected():void {
  [$m,$u,$a]=$this->fixture();$this->actingAs($m);$d=$this->data($a,'existing',$u);
  foreach([['email','other@example.test'],['active',false],['record_version',2]]as[$field,$value]){$old=$u->$field;$u->$field=$value;$u->save();$this->post($this->linkUrl($a),$d)->assertSessionHasErrors('onboarding');$u->$field=$old;$u->save();}
  Apprentice::factory()->create(['user_id'=>$u->id]);$this->post($this->linkUrl($a),$d)->assertSessionHasErrors('onboarding');$this->assertNull($a->fresh()->user_id);
 }
 public function test_missing_identity_confirmation_bad_mode_and_stale_profile_fail_without_writes():void {
  [$m,$u,$a]=$this->fixture();$this->actingAs($m);foreach(['confirmed','identity_reference']as$key){$d=$this->data($a,'existing',$u);unset($d[$key]);$this->post($this->linkUrl($a),$d)->assertSessionHasErrors($key);}
  $d=$this->data($a,'existing',$u);$d['version']=0;$this->post($this->linkUrl($a),$d)->assertSessionHasErrors('version');$d['version']=2;$this->post($this->linkUrl($a),$d)->assertSessionHasErrors('onboarding');$d['mode']='staff';$this->post($this->linkUrl($a),$d)->assertSessionHasErrors('mode');$this->assertDatabaseCount('apprentice_onboarding_events',0);
 }
 public function test_new_mode_cannot_sneak_an_existing_account_identifier():void {
  [$m,$u,$a]=$this->fixture();$d=$this->data($a);$d['existing_user_id']=$u->id;$this->actingAs($m)->post($this->linkUrl($a),$d)->assertSessionHasErrors('existing_user_id');
 }
 public function test_invalid_profile_email_requires_profile_correction_first():void {
  [$m,$u,$a]=$this->fixture();$a->email='not-an-email';$a->save();$this->actingAs($m)->post($this->linkUrl($a),$this->data($a))->assertSessionHasErrors('onboarding');$this->assertNull($a->fresh()->user_id);
 }
 public function test_only_active_managers_can_provision_or_view_accounts():void {
  [$m,$u,$a]=$this->fixture();foreach(['tutor','mentor','employer','apprentice','staff','careers_adviser','inclusion']as$role){$x=User::factory()->create(['role'=>$role]);$this->actingAs($x)->get('/apprentice-onboarding')->assertForbidden();$this->get('/apprentice-onboarding/'.$a->id)->assertForbidden();foreach(['link','detach','invite','revoke']as$action){$this->post('/apprentice-onboarding/'.$a->id.'/'.$action,[])->assertForbidden();}}
  $m->active=false;$m->save();$this->actingAs($m)->get('/apprentice-onboarding')->assertForbidden();$this->assertDatabaseCount('apprentice_onboarding_events',0);
 }
 public function test_service_rechecks_manager_authority_and_access_version():void {
  [$m,$u,$a]=$this->fixture();$m->auth_version=2;$m->save();try{StartUp::link($m,1,$a,$this->data($a,'existing',$u));$this->fail('stale actor accepted');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(403,$e->getStatusCode());}$this->assertNull($a->fresh()->user_id);
 }
 public function test_linked_profile_cannot_be_overwritten_or_replayed():void {
  [$m,$u,$a]=$this->fixture();$d=$this->data($a,'existing',$u);$this->actingAs($m)->post($this->linkUrl($a),$d)->assertSessionHasNoErrors();$this->post($this->linkUrl($a),$d)->assertSessionHasErrors('onboarding');$this->assertDatabaseCount('apprentice_onboarding_events',1);
 }
 public function test_manager_queue_filters_pagination_and_detail_exclude_tokens_and_private_reference():void {
  [$m,$u,$a]=$this->fixture();$a->first_name='<script>Needle</script>';$a->save();$this->actingAs($m);$this->get('/apprentice-onboarding?search=Needle&state=unlinked')->assertOk()->assertHeader('Cache-Control','no-store, private')->assertSee('&lt;script&gt;Needle&lt;/script&gt;',false)->assertDontSee('<script>Needle</script>',false);
  $this->get('/apprentice-onboarding?state=confirmed')->assertDontSee('Needle');$this->post($this->linkUrl($a),$this->data($a,'existing',$u))->assertSessionHasNoErrors();$this->get('/apprentice-onboarding/'.$a->id)->assertOk()->assertSee('Linked account')->assertDontSee('FICTIONAL-PRIVATE');$this->get('/apprentice-onboarding?state=unconfirmed')->assertSee('Needle');$this->get('/apprentice-onboarding?state=unlinked')->assertDontSee('Needle');$this->get('/apprentice-onboarding?state=wrong')->assertSessionHasErrors('state');
  Apprentice::factory()->count(21)->create(['user_id'=>null]);$this->get('/apprentice-onboarding?page=2')->assertOk()->assertSee('page=1');
 }
 public function test_readiness_is_factual_and_checklist_completion_never_overrides_missing_setup():void {
  [$m,$u,$a]=$this->fixture(true);$a->programme_id=Programme::factory()->create()->id;$a->tutor_id=User::factory()->create(['role'=>'tutor'])->id;$a->start_date=today();$a->planned_end_date=today()->addYears(3);$a->save();$u->email_verified_at=now();$u->save();$this->assertSame('Ready to start',StartUp::readiness($a)['state']);
  foreach(array_keys(StartUp::ITEMS)as$item){StartUp::saveCheck($u,1,$item,0,true);}$this->assertSame('Started',StartUp::readiness($a)['state']);$a->tutor_id=null;$a->save();$r=StartUp::readiness($a);$this->assertSame('Needs setup',$r['state']);$this->assertSame(6,$r['confirmed']);$this->assertContains('Main tutor assignment needs checking',$r['issues']);
 }
 public function test_controlled_invitation_is_bound_to_profile_and_single_use_without_auto_login():void {
  [$m,$u,$a]=$this->fixture(true);$this->pilot($u);$path=$this->issue($u,$m);$this->assertDatabaseCount('apprentice_invitation_bindings',1);$this->assertSame(hash('sha256',basename($path)),DB::table('account_onboarding_tokens')->value('token_hash'));
  $this->get('https://staging.example.test'.$path)->assertOk()->assertHeader('Cache-Control','no-store, private')->assertHeader('Referrer-Policy','no-referrer')->assertDontSee($u->email);$this->assertNull($u->fresh()->email_verified_at);
  $this->postPilot($path,$this->password())->assertRedirect('/login');$this->assertGuest();$u->refresh();$this->assertNotNull($u->email_verified_at);$this->assertTrue(Hash::check($this->password()['password'],$u->password));$this->assertSame('apprentice',$u->role);$this->assertDatabaseCount('account_onboarding_tokens',0);$this->assertDatabaseCount('apprentice_invitation_bindings',0);$this->assertDatabaseCount('apprentice_start_checks',0);$this->postPilot($path,$this->password())->assertForbidden();
 }
 public function test_missing_mismatched_or_duplicate_profile_cannot_receive_learner_invite():void {
  [$m,$u,$a]=$this->fixture();$this->pilot($u);$this->assertFalse(AccountOnboarding::eligible($u,'learner_invite'));$a->user_id=$u->id;$a->email='different@example.test';$a->save();$this->assertFalse(AccountOnboarding::eligible($u,'learner_invite'));$a->email=$u->email;$a->save();$this->assertTrue(AccountOnboarding::eligible($u,'learner_invite'));
  DB::statement('DROP INDEX apprentices_user_id_unique');Apprentice::factory()->create(['user_id'=>$u->id,'email'=>$u->email]);$this->assertFalse(AccountOnboarding::eligible($u,'learner_invite'));
 }
 public function test_invitation_identity_change_holds_old_link_but_unrelated_training_change_does_not():void {
  [$m,$u,$a]=$this->fixture(true);$this->pilot($u);$path=$this->issue($u,$m);$a->planned_end_date=today()->addYears(4);$a->version++;$a->save();$this->get('https://staging.example.test'.$path)->assertOk();
  foreach(['first_name','last_name','reference','email']as$field){$old=$a->$field;$a->$field=$field==='email'?'new@example.test':'CHANGED';$a->save();$this->postPilot($path,$this->password())->assertForbidden();$a->$field=$old;$a->save();}$this->assertNull($u->fresh()->email_verified_at);
 }
 public function test_expired_tampered_disabled_pilot_and_issuer_changes_fail_closed():void {
  [$m,$u,$a]=$this->fixture(true);$this->pilot($u);$path=$this->issue($u,$m);$bad=substr($path,0,-1).(str_ends_with($path,'x')?'y':'x');$this->postPilot($bad,$this->password())->assertForbidden();
  foreach([['role','tutor'],['active',false],['auth_version',2]]as[$key,$value]){$old=$m->$key;$m->$key=$value;$m->save();$this->postPilot($path,$this->password())->assertForbidden();$m->$key=$old;$m->save();}
  config(['onboarding.enabled'=>false]);$this->postPilot($path,$this->password())->assertForbidden();config(['onboarding.enabled'=>true,'onboarding.recipients'=>[]]);$this->postPilot($path,$this->password())->assertForbidden();config(['onboarding.recipients'=>[$u->email]]);$this->travel(60)->minutes();$this->postPilot($path,$this->password())->assertForbidden();$this->assertNull($u->fresh()->email_verified_at);
 }
 public function test_bad_password_does_not_consume_valid_invitation():void {
  [$m,$u,$a]=$this->fixture(true);$this->pilot($u);$path=$this->issue($u,$m);$this->postPilot($path,['password'=>'weak','password_confirmation'=>'weak'])->assertSessionHasErrors('password');$this->assertDatabaseCount('account_onboarding_tokens',1);$this->assertNull($u->fresh()->email_verified_at);
 }
 public function test_send_requires_current_profile_and_account_versions():void {
  [$m,$u,$a]=$this->fixture(true);$this->pilot($u);Notification::fake();$this->actingAs($m);$path='/apprentice-onboarding/'.$a->id.'/invite';$this->postPilot($path,['version'=>0,'record_version'=>1])->assertSessionHasErrors('onboarding');$this->postPilot($path,['version'=>1,'record_version'=>0])->assertSessionHasErrors('onboarding');Notification::assertNothingSent();$this->postPilot($path,['version'=>1,'record_version'=>1])->assertSessionHasNoErrors();Notification::assertSentToTimes($u,AccountOnboardingLink::class,1);$this->get('https://staging.example.test/apprentice-onboarding/'.$a->id)->assertOk()->assertDontSee(DB::table('account_onboarding_tokens')->value('token_hash'));
 }
 public function test_send_cooldown_resend_and_revoke_with_switch_off():void {
  [$m,$u,$a]=$this->fixture(true);$this->pilot($u);$old=$this->issue($u,$m);$this->actingAs($m);$this->postPilot('/apprentice-onboarding/'.$a->id.'/invite',['version'=>1,'record_version'=>1])->assertSessionHasErrors('onboarding');$this->travel(61)->seconds();Notification::fake();$this->postPilot('/apprentice-onboarding/'.$a->id.'/invite',['version'=>1,'record_version'=>1])->assertSessionHasNoErrors();$new=parse_url(Notification::sent($u,AccountOnboardingLink::class)->last()->link,PHP_URL_PATH);$this->assertNotSame($old,$new);$this->assertFalse(AccountOnboarding::valid($u,'learner_invite',basename($old)));config(['onboarding.enabled'=>false]);$this->postPilot('/apprentice-onboarding/'.$a->id.'/revoke',['version'=>1])->assertSessionHasNoErrors();$this->assertDatabaseCount('account_onboarding_tokens',0);$this->assertDatabaseCount('apprentice_invitation_bindings',0);
 }
 public function test_transport_rejection_rolls_back_token_binding_and_audit_without_private_errors():void {
  [$m,$u,$a]=$this->fixture(true);$this->pilot($u);Notification::shouldReceive('send')->once()->andThrow(new TransportException('PRIVATE-SMTP-SECRET'));$this->actingAs($m);$response=$this->postPilot('/apprentice-onboarding/'.$a->id.'/invite',['version'=>1,'record_version'=>1])->assertSessionHasErrors('onboarding');$this->assertStringNotContainsString('PRIVATE-SMTP-SECRET',json_encode(session('errors')->all()));$this->assertDatabaseCount('account_onboarding_tokens',0);$this->assertDatabaseCount('apprentice_invitation_bindings',0);$this->assertDatabaseCount('account_access_events',0);
 }
 public function test_detach_invalidates_sessions_tokens_and_retains_work_history_without_transfer():void {
  [$m,$u,$a]=$this->fixture(true);$u->email_verified_at=now();$u->save();StartUp::saveCheck($u,1,'details-v1',0,true);$this->actingAs($m)->post('/apprentice-onboarding/'.$a->id.'/detach',['version'=>1,'identity_reference'=>'FICTIONAL CORRECTION','confirmed'=>'1'])->assertSessionHasNoErrors();$this->assertNull($a->fresh()->user_id);$this->assertSame(2,$u->fresh()->auth_version);$this->assertDatabaseCount('apprentice_start_checks',1);$this->assertDatabaseCount('apprentice_start_check_events',1);$this->assertDatabaseHas('apprentice_onboarding_events',['event'=>'account_unlinked']);$this->assertSame(0,StartUp::readiness($a->fresh())['confirmed']);
  $new=User::factory()->create(['role'=>'apprentice','email'=>'replacement@example.test']);$a->refresh();$a->email=$new->email;$a->save();$this->post($this->linkUrl($a),$this->data($a,'existing',$new))->assertSessionHasNoErrors();$this->assertSame(0,StartUp::readiness($a->fresh())['confirmed']);
 }
 public function test_stale_detach_does_not_remove_current_link():void {
  [$m,$u,$a]=$this->fixture(true);$this->actingAs($m)->post('/apprentice-onboarding/'.$a->id.'/detach',['version'=>2,'identity_reference'=>'FICTIONAL','confirmed'=>'1'])->assertSessionHasErrors('onboarding');$this->assertSame($u->id,$a->fresh()->user_id);$this->assertSame(1,$u->fresh()->auth_version);
 }
 public function test_getting_started_is_own_profile_only_and_staff_cannot_tick_for_apprentice():void {
  [$m,$u,$a]=$this->fixture(true);$u->email_verified_at=now();$u->save();$other=Apprentice::factory()->create(['first_name'=>'PRIVATE-OTHER-PERSON']);$this->actingAs($u)->get('/getting-started')->assertOk()->assertSee($a->reference)->assertDontSee('PRIVATE-OTHER-PERSON')->assertSee('formal induction separately')->assertDontSee('Identity check reference');
  $this->post('/getting-started/details-v1',['version'=>0,'confirmed'=>1,'apprentice_id'=>$other->id])->assertSessionHasNoErrors();$this->assertDatabaseHas('apprentice_start_checks',['user_id'=>$u->id,'apprentice_id'=>$a->id]);$this->actingAs($m)->post('/getting-started/tasks-v1',['version'=>0,'confirmed'=>1])->assertForbidden();$this->get('/getting-started')->assertForbidden();
 }
 public function test_missing_duplicate_and_inactive_profile_access_fails_without_check_writes():void {
  [$m,$u,$a]=$this->fixture();$u->email_verified_at=now();$u->save();$this->actingAs($u)->get('/getting-started')->assertStatus(409);$this->post('/getting-started/details-v1',['version'=>0,'confirmed'=>1])->assertStatus(409);
  $a->user_id=$u->id;$a->save();DB::statement('DROP INDEX apprentices_user_id_unique');Apprentice::factory()->create(['user_id'=>$u->id]);$this->get('/getting-started')->assertStatus(409);$this->post('/getting-started/details-v1',['version'=>0,'confirmed'=>1])->assertStatus(409);$this->assertDatabaseCount('apprentice_start_checks',0);
 }
 public function test_checklist_is_versioned_reversible_and_does_not_change_any_training_or_alert_data():void {
  [$m,$u,$a]=$this->fixture(true);$u->email_verified_at=now();$u->save();$before=$a->fresh()->getAttributes();$this->actingAs($u);$this->post('/getting-started/alerts-v1',['version'=>0,'confirmed'=>1])->assertSessionHasNoErrors();$this->post('/getting-started/alerts-v1',['version'=>0,'confirmed'=>0])->assertSessionHasErrors('check');$this->post('/getting-started/alerts-v1',['version'=>1,'confirmed'=>0])->assertSessionHasNoErrors();$this->assertNull(DB::table('apprentice_start_checks')->value('confirmed_at'));$this->assertSame(2,(int)DB::table('apprentice_start_checks')->value('version'));$this->assertDatabaseCount('apprentice_start_check_events',2);$this->assertSame($before,$a->fresh()->getAttributes());$this->assertDatabaseCount('signed_review_records',0);
 }
 public function test_unknown_items_and_invalid_state_cannot_write_or_reset_existing_checks():void {
  [$m,$u,$a]=$this->fixture(true);$u->email_verified_at=now();$u->save();$this->actingAs($u);$this->post('/getting-started/unknown',['version'=>0,'confirmed'=>1])->assertNotFound();$this->post('/getting-started/details-v1',['version'=>0,'confirmed'=>'yes'])->assertSessionHasErrors('confirmed');$this->post('/getting-started/details-v1',['confirmed'=>1])->assertSessionHasErrors('version');$this->assertDatabaseCount('apprentice_start_checks',0);
 }
 public function test_stale_apprentice_access_version_and_unverified_mailbox_cannot_tick():void {
  [$m,$u,$a]=$this->fixture(true);try{StartUp::saveCheck($u,1,'details-v1',0,true);$this->fail('unverified accepted');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(409,$e->getStatusCode());}$u->email_verified_at=now();$u->auth_version=2;$u->save();try{StartUp::saveCheck($u,1,'details-v1',0,true);$this->fail('stale accepted');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(403,$e->getStatusCode());}$this->assertDatabaseCount('apprentice_start_checks',0);
 }
 public function test_read_only_pages_do_not_create_checklist_or_send_messages():void {
  [$m,$u,$a]=$this->fixture(true);$u->email_verified_at=now();$u->save();Notification::fake();$before=$a->fresh()->getAttributes();$this->actingAs($u)->get('/getting-started')->assertOk();$this->get('/getting-started')->assertOk();$this->actingAs($m)->get('/apprentice-onboarding/'.$a->id)->assertOk();$this->get('/apprentice-onboarding')->assertOk();$this->assertDatabaseCount('apprentice_start_checks',0);$this->assertDatabaseCount('apprentice_onboarding_events',0);$this->assertSame($before,$a->fresh()->getAttributes());Notification::assertNothingSent();
 }
 public function test_onboarding_remains_disabled_in_production_and_does_not_accept_non_learner_invites():void {
  [$m,$u,$a]=$this->fixture(true);$this->pilot($u);$this->assertTrue(AccountOnboarding::eligible($u,'learner_invite'));$this->app['env']='production';$this->assertFalse(AccountOnboarding::eligible($u,'learner_invite'));$this->app['env']='staging';$this->assertFalse(AccountOnboarding::eligible($m,'learner_invite'));$this->assertFalse(AccountOnboarding::eligible($u,'invite'));$this->assertFalse(AccountOnboarding::eligible($u,'employer_invite'));
 }
 public function test_post_only_mutations_and_no_csrf_cannot_create_account():void {
  [$m,$u,$a]=$this->fixture(true);$this->pilot($u);$this->actingAs($m);$this->get('https://staging.example.test/apprentice-onboarding/'.$a->id.'/invite')->assertStatus(405);$this->post('https://staging.example.test/apprentice-onboarding/'.$a->id.'/invite',['version'=>1,'record_version'=>1])->assertStatus(419);$this->assertDatabaseCount('account_onboarding_tokens',0);
 }
}
