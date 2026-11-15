<?php
namespace Tests\Feature;
use App\Models\{User,Apprentice,ApprenticeAlertDelivery as D};
use App\Support\CommunicationReadiness as C;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\{Mail,Http};
use Illuminate\Support\Str;
use Tests\TestCase;
class CommunicationReadinessTest extends TestCase
{
 use LazilyRefreshDatabase;
 private function f():array{$u=User::factory()->create(['role'=>'apprentice','email'=>'private-mailbox46@example.test']);$a=Apprentice::factory()->create(['user_id'=>$u->id]);$a->forceFill(['alert_mobile'=>'+447700900046','alert_channel'=>'both','alerts_enabled'=>true,'alert_mobile_verified_at'=>now()])->save();$admin=User::factory()->create(['role'=>'admin']);return compact('u','a','admin');}
 private function delivery(Apprentice $a,User $u,string $state='Accepted'):D{return D::create(['id'=>(string)Str::uuid(),'apprentice_id'=>$a->id,'recipient_id'=>$u->id,'channel'=>'sms','cycle_date'=>today(),'source'=>['private'=>'PRIVATE-SOURCE-46'],'source_hash'=>str_repeat('a',64),'recipient_hash'=>str_repeat('b',64),'preference_revision'=>$a->alert_revision,'auth_version'=>$u->auth_version,'config_hash'=>str_repeat('c',64),'state'=>$state,'reason'=>'PRIVATE-REASON-46','provider_id'=>'PRIVATE-PROVIDER-46','history'=>[]]);}
 public function test_manager_view_is_read_only_and_hides_contacts_keys_and_provider_details():void{$f=$this->f();$this->delivery($f['a'],$f['u']);config(['apprentice_notifications.key'=>'PRIVATE-VONAGE-KEY-46','apprentice_notifications.secret'=>'PRIVATE-VONAGE-SECRET-46']);Mail::fake();Http::fake();$this->actingAs($f['admin'])->get('/communication-checks')->assertOk()->assertSee('Provider accepted — delivery unconfirmed')->assertDontSee('private-mailbox46@example.test')->assertDontSee('+447700900046')->assertDontSee('PRIVATE-SOURCE-46')->assertDontSee('PRIVATE-PROVIDER-46')->assertDontSee('PRIVATE-VONAGE-KEY-46')->assertDontSee('PRIVATE-VONAGE-SECRET-46')->assertHeader('Referrer-Policy','no-referrer');Mail::assertNothingSent();Http::assertNothingSent();$this->assertDatabaseCount('apprentice_alert_deliveries',1);}
 public function test_own_status_only_lists_current_linked_account_outcomes():void{$f=$this->f();$this->delivery($f['a'],$f['u']);$other=User::factory()->create(['role'=>'apprentice']);$a=Apprentice::factory()->create(['user_id'=>$other->id]);$this->delivery($a,$other,'Rejected');$this->actingAs($f['u'])->get('/my-reminder-status')->assertOk()->assertSee('Provider accepted — delivery unconfirmed')->assertDontSee('Provider rejected')->assertDontSee('private-mailbox46@example.test')->assertDontSee('PRIVATE-REASON-46');}
 public function test_prior_linked_account_history_is_not_inherited_by_new_user():void{$f=$this->f();$old=User::factory()->create(['role'=>'apprentice']);$this->delivery($f['a'],$old);$this->assertSame([],C::person($f['a'])['recent']);}
 public function test_opt_out_is_reported_but_never_reenabled():void{$f=$this->f();$f['a']->forceFill(['alerts_enabled'=>false])->save();$before=$f['a']->fresh()->getAttributes();$r=C::person($f['a']);$this->assertFalse($r['enabled']);$this->assertStringContainsString('switched off',$r['channels']['email']['position']);$this->assertSame($before,$f['a']->fresh()->getAttributes());}
 public function test_contact_prerequisites_are_not_called_delivery_proof():void{$f=$this->f();config(['apprentice_notifications.phones'=>['+447700900046'],'apprentice_notifications.emails'=>[$f['u']->email]]);$r=C::person($f['a']);$this->assertTrue($r['channels']['sms']['eligible_contact']);$this->assertStringContainsString('send-time checks remain',$r['channels']['sms']['position']);}
 public function test_disabled_configuration_is_visible_without_connecting():void{$f=$this->f();config(['apprentice_notifications.enabled'=>false]);$r=C::configuration();$this->assertNotEmpty($r['reminder_blockers']);$this->assertFalse($r['sends_performed']);$this->assertFalse($r['writes_performed']);}
 #[\PHPUnit\Framework\Attributes\TestWith(['tutor'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['employer'])]
 #[\PHPUnit\Framework\Attributes\TestWith(['apprentice'])]
 public function test_non_managers_cannot_see_all_apprentice_communication_checks(string $role):void{$this->actingAs(User::factory()->create(['role'=>$role]))->get('/communication-checks')->assertForbidden();}
 public function test_an_unlinked_apprentice_has_no_guessed_status():void{$this->actingAs(User::factory()->create(['role'=>'apprentice']))->get('/my-reminder-status')->assertStatus(409);}
 public function test_manager_search_counts_are_filtered_before_pagination():void{$f=$this->f();$this->actingAs($f['admin'])->get('/communication-checks?search='.$f['a']->reference)->assertOk()->assertViewHas('people',fn($p)=>$p->total()===1);$this->getJson('/communication-checks?search[]=x')->assertUnprocessable();}
}
