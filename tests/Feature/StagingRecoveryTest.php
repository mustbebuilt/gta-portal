<?php
namespace Tests\Feature;

use App\Models\User;
use App\Support\{AccountAccess,RecoveryDelivery};
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\{DB,Hash,Notification,Password};
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Concerns\ConfiguresStaging;
use Tests\TestCase;

class StagingRecoveryTest extends TestCase
{
    use LazilyRefreshDatabase,ConfiguresStaging;
    private function pilot(): User
    {
        $u=User::factory()->create(['email'=>'pilot@example.test','role'=>'tutor']);$this->configureStaging();
        config(['account_access.staging_recovery_enabled'=>true,'account_access.staging_recipients'=>[$u->email],'mail.default'=>'smtp','mail.mailers.smtp'=>['transport'=>'smtp','scheme'=>'smtps','url'=>null,'host'=>'smtp.example.test','port'=>465,'timeout'=>10],'mail.from.address'=>'portal@example.test']);return $u;
    }
    private function url(string $path): string {return 'https://staging.example.test'.$path;}
    private function pilotPost(string $url,array $data): \Illuminate\Testing\TestResponse {return $this->withSession(['_token'=>'fictional-pilot-csrf'])->post($url,[...$data,'_token'=>'fictional-pilot-csrf']);}
    private function values(User $u,string $token): array {return ['email'=>$u->email,'token'=>$token,'password'=>'Fictional-pilot-passphrase-27!','password_confirmation'=>'Fictional-pilot-passphrase-27!'];}
    public function test_staging_pilot_requires_its_own_explicit_switch_and_does_not_enable_production(): void
    {
        $this->pilot();$this->assertTrue(AccountAccess::recoveryAvailable());config(['account_access.staging_recovery_enabled'=>false,'account_access.recovery_enabled'=>true]);$this->assertFalse(AccountAccess::recoveryAvailable());config(['account_access.staging_recovery_enabled'=>true]);$this->app['env']='production';$this->assertFalse(AccountAccess::recoveryAvailable());
    }
    public function test_allowed_verified_account_uses_broker_token_and_fixed_https_origin(): void
    {
        $u=$this->pilot();Notification::fake();$this->get($this->url('/forgot-password'))->assertOk()->assertSee('Staging recovery pilot')->assertDontSee('Local recovery test');$this->pilotPost($this->url('/forgot-password'),['email'=>strtoupper($u->email)])->assertSessionHasNoErrors();Notification::assertSentTo($u,ResetPassword::class,function($n)use($u){$url=$n->toMail($u)->actionUrl;$this->assertStringStartsWith('https://staging.example.test/reset-password/',$url);$stored=DB::table('password_reset_tokens')->value('token');$this->assertNotSame($n->token,$stored);$this->assertTrue(Hash::check($n->token,$stored));return true;});
    }
    public function test_non_pilot_account_has_same_request_response_and_cannot_use_existing_token(): void
    {
        $this->pilot();$other=User::factory()->create(['email'=>'other@example.test']);$token=Password::broker()->createToken($other);$before=$other->fresh()->getAttributes();Notification::fake();$this->pilotPost($this->url('/forgot-password'),['email'=>$other->email])->assertSessionHas('status','If the account is eligible and recovery is enabled, reset instructions are prepared. Otherwise contact your GTA administrator.');Notification::assertNothingSent();$this->pilotPost($this->url('/reset-password'),$this->values($other,$token))->assertSessionHasErrors('email');$this->assertSame($before,$other->fresh()->getAttributes());
    }
    public function test_inactive_and_unverified_pilot_accounts_get_no_new_tokens_or_notifications(): void
    {
        $u=$this->pilot();Notification::fake();$u->active=false;$u->save();$this->pilotPost($this->url('/forgot-password'),['email'=>$u->email])->assertSessionHasNoErrors();$u->active=true;$u->email_verified_at=null;$u->save();$this->pilotPost($this->url('/forgot-password'),['email'=>$u->email])->assertSessionHasNoErrors();$this->assertDatabaseCount('password_reset_tokens',0);Notification::assertNothingSent();
    }
    public function test_remote_plaintext_url_override_invalid_scheme_and_disabled_peer_verification_block_pilot(): void
    {
        $this->pilot();foreach([['scheme','smtp','remote_smtp_requires_smtps'],['scheme','invalid','smtp_scheme_invalid'],['url','smtp://override.example.test','smtp_url_override_not_allowed'],['url','','smtp_url_override_not_allowed'],['verify_peer',false,'smtp_peer_verification_required'],['timeout',null,'bounded_smtp_timeout_required'],['port','465.5','smtp_host_and_port_required']] as [$key,$value,$error]){$original=config('mail.mailers.smtp');config(['mail.mailers.smtp.'.$key=>$value]);$this->assertContains($error,RecoveryDelivery::errors());$this->assertFalse(AccountAccess::recoveryAvailable());config(['mail.mailers.smtp'=>$original]);}
    }
    public function test_only_literal_loopback_allows_plain_smtp_for_local_rehearsal(): void
    {
        $this->pilot();config(['mail.mailers.smtp.host'=>'127.0.0.1','mail.mailers.smtp.port'=>2525,'mail.mailers.smtp.scheme'=>'smtp']);$this->assertSame([],RecoveryDelivery::errors());config(['mail.mailers.smtp.host'=>'localhost']);$this->assertContains('remote_smtp_requires_smtps',RecoveryDelivery::errors());
    }
    public function test_invalid_empty_or_oversized_pilot_lists_and_wrong_mailer_block_recovery(): void
    {
        $this->pilot();foreach([[],['not-an-email'],array_map(fn($i)=>'pilot'.$i.'@example.test',range(1,11))]as$list){config(['account_access.staging_recipients'=>$list]);$this->assertFalse(AccountAccess::recoveryAvailable());}config(['account_access.staging_recipients'=>['pilot@example.test'],'mail.default'=>'failover']);$this->assertContains('direct_smtp_mailer_required',RecoveryDelivery::errors());
    }
    public function test_smtp_transport_failure_rolls_back_new_token_without_public_provider_details(): void
    {
        $u=$this->pilot();$before=$u->fresh()->getAttributes();Notification::shouldReceive('send')->once()->andThrow(new TransportException('PRIVATE SMTP TRANSCRIPT AND TOKEN'));$this->pilotPost($this->url('/forgot-password'),['email'=>$u->email])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('status','If the account is eligible and recovery is enabled, reset instructions are prepared. Otherwise contact your GTA administrator.');$this->assertDatabaseCount('password_reset_tokens',0);$this->assertDatabaseCount('account_access_events',0);$this->assertSame($before,$u->fresh()->getAttributes());
    }
    public function test_reset_is_single_use_preserves_role_and_invalidates_old_auth_version(): void
    {
        $u=$this->pilot();$token=Password::broker()->createToken($u);$this->pilotPost($this->url('/reset-password'),$this->values($u,$token))->assertRedirect('/login')->assertSessionHasNoErrors();$this->assertTrue(Hash::check('Fictional-pilot-passphrase-27!',$u->fresh()->password));$this->assertSame('tutor',$u->fresh()->role);$this->assertSame(2,$u->fresh()->auth_version);$this->assertDatabaseCount('password_reset_tokens',0);$this->pilotPost($this->url('/reset-password'),$this->values($u,$token))->assertSessionHasErrors('email');$this->assertDatabaseCount('account_access_events',1);
    }
    public function test_removing_pilot_recipient_or_disabling_switch_invalidates_reset_availability(): void
    {
        $u=$this->pilot();$token=Password::broker()->createToken($u);config(['account_access.staging_recipients'=>['different@example.test']]);$this->pilotPost($this->url('/reset-password'),$this->values($u,$token))->assertSessionHasErrors('email');config(['account_access.staging_recipients'=>[$u->email],'account_access.staging_recovery_enabled'=>false]);$this->pilotPost($this->url('/reset-password'),$this->values($u,$token))->assertSessionHasErrors('email');$this->assertSame(1,$u->fresh()->auth_version);
    }
    public function test_configuration_inspection_is_read_only_and_excludes_addresses_hosts_credentials_and_urls(): void
    {
        $u=$this->pilot();config(['mail.mailers.smtp.username'=>'PRIVATE USER','mail.mailers.smtp.password'=>'PRIVATE SECRET']);$before=$u->fresh()->getAttributes();$result=RecoveryDelivery::inspect();$this->assertSame('configuration_checks_only',$result['result']);$this->assertFalse($result['sends_performed']);$json=json_encode($result);foreach([$u->email,'smtp.example.test','PRIVATE USER','PRIVATE SECRET','https://staging.example.test']as$value){$this->assertStringNotContainsString($value,$json);}$this->artisan('gta:recovery-check')->assertExitCode(0);$this->assertSame($before,$u->fresh()->getAttributes());$this->assertDatabaseCount('password_reset_tokens',0);
    }
    public function test_invalid_staging_gate_and_wrong_request_host_cannot_issue_a_recovery_token(): void
    {
        $u=$this->pilot();Notification::fake();$this->pilotPost('https://other.example.test/forgot-password',['email'=>$u->email])->assertStatus(503);config(['session.encrypt'=>false]);$this->assertFalse(AccountAccess::recoveryAvailable());$this->assertDatabaseCount('password_reset_tokens',0);Notification::assertNothingSent();
    }
}
