<?php
namespace Tests\Feature;
use App\Models\User;
use App\Support\ReleaseSourceCheck as R;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;
class PortalRelease45Test extends TestCase
{
 use LazilyRefreshDatabase;
 public function test_login_uses_real_fields_and_separate_decorative_artwork():void{
  $this->get('/login')->assertOk()->assertSee('GTA Portal')->assertSee('gta-login-scene.webp',false)->assertSee('name="email"',false)->assertSee('autocomplete="current-password"',false)->assertSee('gta-logo.jpg',false)->assertDontSee('01_APPROVED_GTA_Portal_Login.png',false)->assertDontSee('Visual concept');
 }
 public function test_guest_accessibility_and_contact_destinations_are_functional():void{
  $this->get('/accessibility')->assertOk()->assertSee('Accessibility and help')->assertSee('not a claim that a formal accessibility audit');$this->get('/privacy-information')->assertOk()->assertSee('id="contact"',false);
 }
 public function test_source_check_is_read_only_and_not_target_approval():void{
  $report=R::report();$this->assertSame('requested_checks_passed',$report['status']);$this->assertFalse($report['writes_performed']);$this->assertFalse($report['actual_target_acceptance']);$this->assertSame('not_checked',$report['schema']['status']);$this->assertDatabaseCount('users',0);$this->assertDatabaseCount('audit_events',0);
 }
 public function test_schema_check_does_not_misreport_sqlite_as_native_mysql():void{
  $r=R::report(false,true);$this->assertSame('needs_attention',$r['status']);$this->assertSame('actual_driver_not_supported',$r['schema']['reason']);$this->assertFalse($r['schema']['writes_performed']);
 }
 public function test_asset_drift_is_detected_and_original_is_retained_after_probe():void{
  $p=public_path('assets/gta-mark.svg');$original=file_get_contents($p);try{file_put_contents($p,$original.'CORRUPT');$r=R::report();$this->assertFalse($r['checks']['visual_assets']);$this->assertSame('needs_attention',$r['status']);}finally{file_put_contents($p,$original);}
 }
 public function test_optional_configuration_checks_never_print_secret_values():void{
  config(['app.key'=>'PRIVATE-APP-KEY-45','database.connections.mysql.password'=>'PRIVATE-DB-PASSWORD-45']);$r=R::report(true);$json=json_encode($r);$this->assertStringNotContainsString('PRIVATE-APP-KEY-45',$json);$this->assertStringNotContainsString('PRIVATE-DB-PASSWORD-45',$json);$this->assertContains('valid_application_key_required',$r['configuration']['errors']);
 }
 public function test_artisan_source_command_has_explicit_scope_and_no_side_effects():void{
  $this->artisan('gta:release-check45')->expectsOutputToContain('"actual_target_acceptance":false')->assertExitCode(0);$this->assertDatabaseCount('users',0);
 }
 public function test_current_dashboard_and_apprentice_home_link_to_scoped_report():void{
  $u=User::factory()->create(['role'=>'apprentice']);$a=\App\Models\Apprentice::factory()->create(['user_id'=>$u->id]);$this->actingAs($u)->get('/my-next-step')->assertOk()->assertSee('My report: charts and exact figures');$this->get('/dashboard')->assertOk()->assertSee('data-visual-panel="tasks"',false)->assertSee('report-visuals.js',false);
 }
 public function test_low_count_and_unknown_cohort_charts_do_not_fabricate_percentages():void{
  $c=\App\Support\CohortInsights::emptyCounts();$c['cohort']=1;$c['unknown']=1;$charts=\App\Support\PortalVisuals::cohort(['counts'=>$c,'groups'=>[]]);$this->assertSame(1,collect($charts[0]['rows'])->firstWhere('label','Unknown / check')['value']);$this->assertSame('bars',$charts[0]['type']);$this->assertSame([],$charts[1]['rows']);
 }
}
