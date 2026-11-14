<?php
namespace Tests\Feature;
use App\Models\{Apprentice,User};
use App\Support\ApprenticeAlerts;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\{DB,Schema};
use Tests\TestCase;
class ApprenticeAlertPreferencesTest extends TestCase {
 use LazilyRefreshDatabase;
 private function fixture():array {$u=User::factory()->create(['role'=>'apprentice']);$a=Apprentice::factory()->create(['user_id'=>$u->id]);return [$u,$a];}
 public function test_create_form_records_on_and_both_with_encrypted_mobile_and_no_verification():void {
  $admin=User::factory()->create(['role'=>'admin']);$this->actingAs($admin)->get('/apprentices/create')->assertOk()->assertSee('Apprentice reminders')->assertSee('Both — email and text message')->assertSee('On — recommended');
  $this->post('/apprentices',['reference'=>'ALERT-NEW','first_name'=>'Fictional','last_name'=>'Learner','change_alert_preferences'=>1,'alert_channel'=>'both','alerts_enabled'=>1,'alert_revision'=>0,'alert_mobile'=>'07700 900123'])->assertRedirect()->assertSessionHasNoErrors();
  $a=Apprentice::where('reference','ALERT-NEW')->firstOrFail();$this->assertTrue($a->alerts_enabled);$this->assertSame('both',$a->alert_channel);$this->assertSame('+447700900123',$a->alert_mobile);$this->assertNull($a->alert_mobile_verified_at);$this->assertSame(1,$a->alert_revision);$this->assertSame(1,$a->version);
  $this->assertNotSame('+447700900123',DB::table('apprentices')->where('id',$a->id)->value('alert_mobile'));$this->assertArrayNotHasKey('alert_mobile',$a->toArray());$this->assertStringNotContainsString('7700900123',json_encode($a->alert_history));
 }
 public function test_legacy_records_and_api_creates_are_not_silently_enrolled():void {
  [$u,$a]=$this->fixture();$this->assertNull($a->fresh()->alerts_enabled);$this->assertNull($a->fresh()->alert_channel);$this->assertFalse(ApprenticeAlerts::wants($a,'email'));
  $admin=User::factory()->create(['role'=>'admin']);$this->actingAs($admin)->post('/apprentices',['reference'=>'LEGACY-API','first_name'=>'Fictional','last_name'=>'Legacy'])->assertSessionHasNoErrors();$a=Apprentice::where('reference','LEGACY-API')->firstOrFail();$this->assertNull($a->alerts_enabled);$this->assertSame(0,$a->alert_revision);
 }
 public function test_apprentice_can_select_both_turn_off_and_back_on_without_changing_other_profile_fields():void {
  [$u,$a]=$this->fixture();$name=$a->first_name;$this->actingAs($u)->get('/account/alerts')->assertOk()->assertSee('recommend keeping reminders on')->assertSee('Off');
  $data=['alert_channel'=>'both','alerts_enabled'=>1,'alert_revision'=>0,'alert_mobile'=>'+447700900123'];$this->post('/account/alerts',$data)->assertRedirect()->assertSessionHasNoErrors();$a->refresh();$this->assertTrue(ApprenticeAlerts::wants($a,'sms'));$this->assertTrue(ApprenticeAlerts::wants($a,'email'));
  $this->post('/account/alerts',[...$data,'alert_revision'=>1,'alerts_enabled'=>0,'first_name'=>'Forged name'])->assertSessionHasNoErrors();$a->refresh();$this->assertFalse(ApprenticeAlerts::wants($a,'sms'));$this->assertFalse(ApprenticeAlerts::wants($a,'email'));$this->assertSame($name,$a->first_name);
  $this->post('/account/alerts',[...$data,'alert_revision'=>2,'alerts_enabled'=>1])->assertSessionHasNoErrors();$this->assertTrue($a->fresh()->alerts_enabled);$this->assertCount(3,$a->fresh()->alert_history);
 }
 public function test_turning_off_does_not_require_a_missing_sms_number():void {
  [$u,$a]=$this->fixture();$this->actingAs($u)->post('/account/alerts',['alert_channel'=>'sms','alerts_enabled'=>0,'alert_revision'=>0,'alert_mobile'=>''])->assertSessionHasNoErrors();$this->assertFalse($a->fresh()->alerts_enabled);$this->assertNull($a->fresh()->alert_mobile);
 }
 public function test_enabled_sms_requires_valid_number_and_unknown_channels_are_rejected():void {
  [$u,$a]=$this->fixture();$this->actingAs($u);$data=['alert_channel'=>'sms','alerts_enabled'=>1,'alert_revision'=>0];$this->post('/account/alerts',$data)->assertSessionHasErrors('alert_mobile');$this->post('/account/alerts',[...$data,'alert_mobile'=>'not-a-phone'])->assertSessionHasErrors('alert_mobile');$this->post('/account/alerts',[...$data,'alert_channel'=>'staff-broadcast'])->assertSessionHasErrors('alert_channel');$this->assertSame(0,$a->fresh()->alert_revision);
 }
 public function test_other_roles_and_other_apprentices_cannot_change_this_preference():void {
  [$u,$a]=$this->fixture();foreach(['admin','ceo','tutor','mentor','employer','staff']as$role){$this->actingAs(User::factory()->create(['role'=>$role]))->get('/account/alerts')->assertForbidden();}
  [$other,$b]=$this->fixture();$this->actingAs($other)->post('/account/alerts',['apprentice_id'=>$a->id,'alert_channel'=>'email','alerts_enabled'=>0,'alert_revision'=>0])->assertSessionHasNoErrors();$this->assertNull($a->fresh()->alerts_enabled);$this->assertFalse($b->fresh()->alerts_enabled);
 }
 public function test_missing_account_link_is_refused_without_guessing():void {
  $u=User::factory()->create(['role'=>'apprentice']);$this->actingAs($u)->get('/account/alerts')->assertStatus(409);$this->post('/account/alerts',['alert_channel'=>'email','alerts_enabled'=>0,'alert_revision'=>0])->assertStatus(409);
 }
 public function test_stale_edits_and_unrelated_management_saves_preserve_opt_out():void {
  [$u,$a]=$this->fixture();$this->actingAs($u)->post('/account/alerts',['alert_channel'=>'email','alerts_enabled'=>0,'alert_revision'=>0])->assertSessionHasNoErrors();$a->refresh();
  $this->post('/account/alerts',['alert_channel'=>'both','alerts_enabled'=>1,'alert_revision'=>0,'alert_mobile'=>'+447700900123'])->assertSessionHasErrors('alert_revision');$this->assertFalse($a->fresh()->alerts_enabled);
  $admin=User::factory()->create(['role'=>'admin']);$this->actingAs($admin)->put('/apprentices/'.$a->id,['version'=>$a->version,'reference'=>$a->reference,'first_name'=>$a->first_name,'last_name'=>$a->last_name,'alert_channel'=>'both','alerts_enabled'=>1,'alert_revision'=>0,'change_alert_preferences'=>0])->assertSessionHasNoErrors();$this->assertFalse($a->fresh()->alerts_enabled);$this->assertSame('email',$a->fresh()->alert_channel);
 }
 public function test_management_cannot_override_an_apprentices_latest_personal_opt_out():void {
  [$u,$a]=$this->fixture();$this->actingAs($u)->post('/account/alerts',['alert_channel'=>'email','alerts_enabled'=>0,'alert_revision'=>0])->assertSessionHasNoErrors();$a->refresh();$admin=User::factory()->create(['role'=>'admin']);
  $this->actingAs($admin)->put('/apprentices/'.$a->id,['version'=>$a->version,'reference'=>$a->reference,'first_name'=>$a->first_name,'last_name'=>$a->last_name,'change_alert_preferences'=>1,'alert_channel'=>'email','alerts_enabled'=>1,'alert_revision'=>1])->assertSessionHasErrors('alerts_enabled');$this->assertFalse($a->fresh()->alerts_enabled);
  $this->actingAs($u)->post('/account/alerts',['alert_channel'=>'email','alerts_enabled'=>1,'alert_revision'=>1])->assertSessionHasNoErrors();$this->assertTrue($a->fresh()->alerts_enabled);
 }
 public function test_phone_change_clears_verification_and_forged_verification_is_ignored():void {
  [$u,$a]=$this->fixture();$a->alert_mobile='+447700900123';$a->alert_mobile_verified_at=now();$a->save();$this->actingAs($u)->post('/account/alerts',['alert_channel'=>'sms','alerts_enabled'=>1,'alert_revision'=>0,'alert_mobile'=>'+447700900124','alert_mobile_verified_at'=>now()->toIso8601String()])->assertSessionHasNoErrors();$this->assertNull($a->fresh()->alert_mobile_verified_at);$this->assertSame('+447700900124',$a->fresh()->alert_mobile);
 }
 public function test_noop_save_has_no_extra_history_and_reads_do_not_send_or_change_data():void {
  [$u,$a]=$this->fixture();$this->actingAs($u)->post('/account/alerts',['alert_channel'=>'email','alerts_enabled'=>0,'alert_revision'=>0])->assertSessionHasNoErrors();$before=DB::table('apprentices')->where('id',$a->id)->first();$this->get('/account/alerts')->assertOk()->assertHeader('Cache-Control','no-store, private');$this->post('/account/alerts',['alert_channel'=>'email','alerts_enabled'=>0,'alert_revision'=>1])->assertSessionHasNoErrors();$this->assertEquals($before,DB::table('apprentices')->where('id',$a->id)->first());
 }
 public function test_preference_audit_failure_rolls_back_opt_choice_phone_history_and_version():void {
  [$u,$a]=$this->fixture();$before=DB::table('apprentices')->where('id',$a->id)->first();\Tests\Support\AuditFault::install('preference_audit_failure','audit_events','Fictional failure');
  $this->actingAs($u)->post('/account/alerts',['alert_channel'=>'both','alerts_enabled'=>1,'alert_revision'=>0,'alert_mobile'=>'+447700900123'])->assertStatus(500);$this->assertEquals($before,DB::table('apprentices')->where('id',$a->id)->first());
 }
 public function test_preference_migration_rollback_keeps_profile_and_task_history_schema():void {
  [$u,$a]=$this->fixture();$migration=require database_path('migrations/2026_11_14_190000_add_apprentice_alert_preferences.php');$migration->down();$this->assertFalse(Schema::hasColumn('apprentices','alerts_enabled'));$this->assertTrue(Schema::hasColumn('learning_tasks','deadline_history'));$migration->up();$this->assertNotNull($a->fresh());$this->assertNull($a->fresh()->alerts_enabled);
 }
}
