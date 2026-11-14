<?php
namespace Tests\Feature;
use App\Models\User;
use App\Notifications\AccountOnboardingLink;
use App\Support\{AccountAccess,AccountOnboarding};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\{DB,Hash,Notification};
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Concerns\ConfiguresStaging;
use Tests\TestCase;
class AccountOnboardingTest extends TestCase
{
    use LazilyRefreshDatabase,ConfiguresStaging;
    private function pilot(): User
    {
        $u=User::factory()->unverified()->create(['email'=>'staff@example.test','role'=>'tutor']);$this->configureStaging();
        config(['onboarding.enabled'=>true,'onboarding.recipients'=>[$u->email],'mail.default'=>'smtp','mail.mailers.smtp'=>['transport'=>'smtp','scheme'=>'smtps','url'=>null,'host'=>'smtp.example.test','port'=>465,'timeout'=>10],'mail.from.address'=>'portal@example.test']);return $u;
    }
    private function url(string $p): string{return 'https://staging.example.test'.$p;}
    private function postPilot(string $p,array $data=[]){return $this->withSession(['_token'=>'fictional-csrf'])->post($this->url($p),[...$data,'_token'=>'fictional-csrf']);}
    private function issue(User $u,string $purpose,?User $issuer=null): string
    {
        Notification::fake();DB::transaction(fn()=>AccountOnboarding::issue($u,$purpose,$issuer));
        $link=Notification::sent($u,AccountOnboardingLink::class)->last()->link;
        $this->assertStringStartsWith('https://staging.example.test/',$link);return parse_url($link,PHP_URL_PATH);
    }
    private function password(): array{return ['password'=>'Fictional-onboarding-passphrase-27!','password_confirmation'=>'Fictional-onboarding-passphrase-27!'];}
    public function test_independent_switch_defaults_off_and_all_non_staging_environments_block(): void
    {
        $this->assertFalse(AccountOnboarding::available());$this->pilot();$this->assertTrue(AccountOnboarding::available());config(['onboarding.enabled'=>false,'account_access.staging_recovery_enabled'=>true]);$this->assertFalse(AccountOnboarding::available());config(['onboarding.enabled'=>true]);foreach(['production','local','testing']as$env){$this->app['env']=$env;$this->assertFalse(AccountOnboarding::available());}
    }
    public function test_signed_in_email_confirmation_requires_post_is_single_use_and_signs_out(): void
    {
        $u=$this->pilot();$path=$this->issue($u,'verify');$stored=DB::table('account_onboarding_tokens')->value('token_hash');$this->assertNotSame(basename($path),$stored);$this->assertSame(hash('sha256',basename($path)),$stored);
        $this->actingAs($u)->get($this->url($path))->assertOk()->assertHeader('Referrer-Policy','no-referrer');$this->assertNull($u->fresh()->email_verified_at);
        $this->postPilot($path)->assertRedirect('/login');$this->assertGuest();$this->assertNotNull($u->fresh()->email_verified_at);$this->assertSame(2,$u->fresh()->auth_version);$this->assertDatabaseCount('account_onboarding_tokens',0);$this->actingAs($u->fresh())->postPilot($path)->assertForbidden();
    }
    public function test_verification_link_is_bound_to_signed_in_account_and_expiry(): void
    {
        $u=$this->pilot();$path=$this->issue($u,'verify');$other=User::factory()->unverified()->create();$this->actingAs($other)->get($this->url($path))->assertForbidden();$this->actingAs($u);$this->travel(60)->minutes();$this->get($this->url($path))->assertForbidden();$this->postPilot($path)->assertForbidden();$this->assertNull($u->fresh()->email_verified_at);
    }
    public function test_verification_send_is_self_only_and_throttled(): void
    {
        $u=$this->pilot();Notification::fake();$this->actingAs($u)->get($this->url('/account/email'))->assertOk()->assertSee('Send verification link');$this->postPilot('/account/email',['user_id'=>999])->assertRedirect('/account/email');Notification::assertSentToTimes($u,AccountOnboardingLink::class,1);$this->postPilot('/account/email')->assertSessionHasErrors('onboarding');Notification::assertSentToTimes($u,AccountOnboardingLink::class,1);
    }
    public function test_verification_transport_rejection_rolls_back_without_private_error(): void
    {
        $u=$this->pilot();Notification::shouldReceive('send')->once()->andThrow(new TransportException('PRIVATE-SMTP-SECRET'));$this->actingAs($u);$this->postPilot('/account/email')->assertSessionHasErrors('onboarding');$this->assertDatabaseCount('account_onboarding_tokens',0);$this->assertDatabaseCount('account_access_events',0);
    }
    public function test_invitation_sets_password_verifies_mailbox_and_preserves_role_without_auto_login(): void
    {
        $u=$this->pilot();$admin=User::factory()->create(['role'=>'admin']);$path=$this->issue($u,'invite',$admin);$this->get($this->url($path))->assertOk()->assertHeader('Referrer-Policy','no-referrer')->assertDontSee($u->email);$this->assertNull($u->fresh()->email_verified_at);
        $this->postPilot($path,$this->password())->assertRedirect('/login');$this->assertGuest();$this->assertTrue(Hash::check($this->password()['password'],$u->fresh()->password));$this->assertSame('tutor',$u->fresh()->role);$this->assertNotNull($u->fresh()->email_verified_at);$this->assertSame(2,$u->fresh()->auth_version);$this->assertDatabaseCount('account_onboarding_tokens',0);$this->postPilot($path,$this->password())->assertForbidden();$this->assertDatabaseHas('account_access_events',['user_id'=>$u->id,'event'=>'invite_accepted']);
    }
    public function test_invitation_weak_password_never_consumes_token(): void
    {
        $u=$this->pilot();$admin=User::factory()->create(['role'=>'admin']);$path=$this->issue($u,'invite',$admin);$this->postPilot($path,['password'=>'weak','password_confirmation'=>'weak'])->assertSessionHasErrors('password');$this->assertDatabaseCount('account_onboarding_tokens',1);$this->assertNull($u->fresh()->email_verified_at);
    }
    public function test_invitation_tamper_expiry_recipient_removal_and_disabled_pilot_fail_closed(): void
    {
        $u=$this->pilot();$admin=User::factory()->create(['role'=>'admin']);$path=$this->issue($u,'invite',$admin);$bad=substr($path,0,-1).(substr($path,-1)==='x'?'y':'x');$this->postPilot($bad,$this->password())->assertForbidden();config(['onboarding.recipients'=>['other@example.test']]);$this->get($this->url($path))->assertForbidden();config(['onboarding.recipients'=>[$u->email],'onboarding.enabled'=>false]);$this->postPilot($path,$this->password())->assertForbidden();config(['onboarding.enabled'=>true]);$this->travel(60)->minutes();$this->postPilot($path,$this->password())->assertForbidden();$this->assertSame(1,$u->fresh()->auth_version);
    }
    public function test_invitation_issuer_loss_of_authority_or_access_version_invalidates_link(): void
    {
        $u=$this->pilot();$admin=User::factory()->create(['role'=>'admin']);$path=$this->issue($u,'invite',$admin);
        foreach([['active',false],['role','tutor'],['auth_version',2]]as[$key,$value]){$old=$admin->$key;$admin->$key=$value;$admin->save();$this->postPilot($path,$this->password())->assertForbidden();$admin->$key=$old;$admin->save();}$this->assertNull($u->fresh()->email_verified_at);
    }
    public function test_target_access_changes_delete_both_kinds_of_link_and_old_password_tokens(): void
    {
        $u=$this->pilot();$admin=User::factory()->create(['role'=>'admin']);$path=$this->issue($u,'invite',$admin);$this->issue($u,'verify');DB::transaction(function()use($u,$admin){$u->email='new@example.test';AccountAccess::invalidate($u,$admin->id,'staff_access_changed','administrator');});$this->assertDatabaseCount('account_onboarding_tokens',0);$this->postPilot($path,$this->password())->assertForbidden();$this->assertNull($u->fresh()->email_verified_at);
    }
    public function test_only_administrators_can_view_send_or_revoke_and_stale_staff_version_is_rejected(): void
    {
        $u=$this->pilot();$this->actingAs($u)->get($this->url('/staff-onboarding'))->assertForbidden();$this->postPilot('/staff-onboarding/'.$u->id.'/invite',['record_version'=>1])->assertForbidden();$this->postPilot('/staff-onboarding/'.$u->id.'/revoke')->assertForbidden();
        $admin=User::factory()->create(['role'=>'admin']);$this->actingAs($admin);Notification::fake();$this->postPilot('/staff-onboarding/'.$u->id.'/invite',['record_version'=>0])->assertSessionHasErrors('onboarding');Notification::assertNothingSent();$this->postPilot('/staff-onboarding/'.$u->id.'/invite',['record_version'=>1])->assertSessionHasNoErrors();Notification::assertSentToTimes($u,AccountOnboardingLink::class,1);
    }
    public function test_resend_replaces_only_after_cooldown_and_revocation_works_when_pilot_disabled(): void
    {
        $u=$this->pilot();$admin=User::factory()->create(['role'=>'ceo']);$path=$this->issue($u,'invite',$admin);$this->travel(61)->seconds();$new=$this->issue($u,'invite',$admin);$this->assertNotSame($path,$new);$this->get($this->url($path))->assertForbidden();$this->get($this->url($new))->assertOk();$this->actingAs($admin);config(['onboarding.enabled'=>false]);$this->postPilot('/staff-onboarding/'.$u->id.'/revoke')->assertSessionHasNoErrors();$this->assertDatabaseCount('account_onboarding_tokens',0);$this->assertDatabaseHas('account_access_events',['event'=>'invite_revoked']);
    }
    public function test_invite_transport_rejection_preserves_previous_link_and_audit(): void
    {
        $u=$this->pilot();$admin=User::factory()->create(['role'=>'admin']);$this->issue($u,'invite',$admin);$old=DB::table('account_onboarding_tokens')->first();$this->travel(61)->seconds();Notification::swap(app(\Illuminate\Notifications\ChannelManager::class));Notification::shouldReceive('send')->once()->andThrow(new TransportException('PRIVATE-SMTP-SECRET'));$this->actingAs($admin);$this->postPilot('/staff-onboarding/'.$u->id.'/invite',['record_version'=>1])->assertSessionHasErrors('onboarding');$this->assertEquals($old,DB::table('account_onboarding_tokens')->first());$this->assertDatabaseCount('account_access_events',1);
    }
    public function test_verified_inactive_ambiguous_and_privileged_accounts_cannot_receive_staff_invites(): void
    {
        $u=$this->pilot();$admin=User::factory()->create(['role'=>'admin']);Notification::fake();$this->actingAs($admin);
        foreach([['active',false],['email_verified_at',now()],['role','admin'],['role','ceo'],['role','apprentice'],['role','employer']]as[$key,$value]){$original=$u->$key;$u->$key=$value;$u->save();$this->postPilot('/staff-onboarding/'.$u->id.'/invite',['record_version'=>1])->assertForbidden();$u->$key=$original;$u->save();\Illuminate\Support\Facades\RateLimiter::clear('onboarding:'.hash('sha256',$admin->id.'|127.0.0.1'));}Notification::assertNothingSent();
    }
    public function test_queue_search_status_filters_pagination_privacy_and_no_token_disclosure(): void
    {
        $u=$this->pilot();$u->name='Needle Alpha';$u->save();$admin=User::factory()->create(['role'=>'admin','name'=>'Privileged Hidden']);$this->issue($u,'invite',$admin);User::factory()->create(['role'=>'staff','name'=>'Verified Hidden']);User::factory()->unverified()->create(['role'=>'staff','name'=>'Inactive Hidden','active'=>false]);User::factory()->count(21)->unverified()->create(['role'=>'tutor']);$this->actingAs($admin);
        $this->get($this->url('/staff-onboarding?search=Needle&state=unverified'))->assertOk()->assertSee('Needle Alpha')->assertDontSee('/directories/staff/'.$admin->id)->assertDontSee('Verified Hidden')->assertDontSee('Inactive Hidden')->assertDontSee(DB::table('account_onboarding_tokens')->value('token_hash'));
        $this->get($this->url('/staff-onboarding?state=unverified&page=2'))->assertOk()->assertSee('page=1');$this->get($this->url('/staff-onboarding?state=verified'))->assertOk()->assertSee('Verified Hidden')->assertDontSee('Needle Alpha');$this->get($this->url('/staff-onboarding?state=inactive'))->assertSee('Inactive Hidden');$this->get($this->url('/staff-onboarding?state=invalid'))->assertSessionHasErrors('state');
    }
    public function test_preflight_is_read_only_contains_no_addresses_or_credentials_and_no_mail(): void
    {
        $u=$this->pilot();Notification::fake();$before=$u->fresh()->getAttributes();config(['mail.mailers.smtp.password'=>'PRIVATE SECRET']);$result=AccountOnboarding::inspect();$this->assertSame('configuration_checks_only',$result['result']);$this->assertFalse($result['writes_performed']);$this->assertFalse($result['sends_performed']);foreach([$u->email,'smtp.example.test','PRIVATE SECRET']as$value){$this->assertStringNotContainsString($value,json_encode($result));}$this->artisan('gta:onboarding-check')->assertExitCode(0);Notification::assertNothingSent();$this->assertSame($before,$u->fresh()->getAttributes());$this->assertDatabaseCount('account_onboarding_tokens',0);
    }
    public function test_wrong_host_and_missing_csrf_cannot_change_accounts(): void
    {
        $u=$this->pilot();$this->actingAs($u);$this->withSession(['_token'=>'fixture'])->post('https://other.example.test/account/email',['_token'=>'fixture'])->assertStatus(503);$this->post($this->url('/account/email'))->assertStatus(419);$this->assertDatabaseCount('account_onboarding_tokens',0);
    }
    public function test_case_ambiguous_email_and_changed_target_role_cannot_accept_a_link(): void
    {
        $u=$this->pilot();$admin=User::factory()->create(['role'=>'admin']);$path=$this->issue($u,'invite',$admin);
        User::factory()->unverified()->create(['email'=>strtoupper($u->email),'role'=>'tutor']);
        $this->postPilot($path,$this->password())->assertForbidden();$this->assertNull($u->fresh()->email_verified_at);
        User::where('email',strtoupper($u->email))->delete();$u->role='ceo';$u->save();$this->postPilot($path,$this->password())->assertForbidden();$this->assertSame(1,$u->fresh()->auth_version);
    }
    public function test_stale_administrator_session_cannot_send_even_if_route_was_previously_open(): void
    {
        $u=$this->pilot();$admin=User::factory()->create(['role'=>'admin']);$this->actingAs($admin);$admin->auth_version++;$admin->save();Notification::fake();$this->postPilot('/staff-onboarding/'.$u->id.'/invite',['record_version'=>1])->assertRedirect('/login');Notification::assertNothingSent();$this->assertDatabaseCount('account_onboarding_tokens',0);
    }
    public function test_invalid_remote_transport_blocks_onboarding_and_current_link_acceptance(): void
    {
        $u=$this->pilot();$admin=User::factory()->create(['role'=>'admin']);$path=$this->issue($u,'invite',$admin);config(['mail.mailers.smtp.scheme'=>'smtp']);$this->assertContains('remote_smtp_requires_smtps',AccountOnboarding::errors());$this->get($this->url($path))->assertForbidden();$this->assertNull($u->fresh()->email_verified_at);
    }
}
