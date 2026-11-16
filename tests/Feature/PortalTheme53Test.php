<?php
namespace Tests\Feature;
use App\Models\{User,Apprentice};
use App\Support\Release53Check;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;
class PortalTheme53Test extends TestCase
{
 use LazilyRefreshDatabase;
 public function test_login_and_recovery_share_early_theme_and_original_brand():void{
  foreach(['/login','/forgot-password']as$url){$r=$this->get($url);$r->assertOk()->assertSee('theme-boot53.js',false)->assertSee('theme53.css',false);$s=$r->getContent();$this->assertLessThan(strpos($s,'portal.css'),strpos($s,'theme-boot53.js'));$this->assertLessThan(strpos($s,'portal.js'),strpos($s,'theme53.css'));$this->assertSame(1,substr_count($s,'theme53.css'));$this->assertStringContainsString('gta-logo.jpg',$s);$this->assertStringNotContainsString('gta-mark.svg',$s);}
 }
 #[TestWith(['admin','/apprentices'])] #[TestWith(['ceo','/apprentices'])] #[TestWith(['tutor','/apprentices'])] #[TestWith(['apprentice','/my-next-step'])] #[TestWith(['employer','/employer-actions'])] #[TestWith(['manager','/staff-development'])] #[TestWith(['staff','/staff-development'])] #[TestWith(['mentor','/reviews'])] #[TestWith(['careers_adviser','/careers'])] #[TestWith(['functional_skills_tutor','/maths-english'])] #[TestWith(['inclusion_lead','/help'])] #[TestWith(['inclusion_member','/help'])]
 public function test_existing_role_pages_inherit_the_shared_theme_without_new_access(string$role,string$url):void{
  $user=User::factory()->create(['role'=>$role]);if($role==='apprentice')Apprentice::factory()->create(['user_id'=>$user->id]);$r=$this->actingAs($user)->get($url);$r->assertOk()->assertSee('theme53.css',false)->assertSee('gta-logo.jpg',false)->assertSee('lang="en-GB"',false);
 }
 public function test_declared_pairs_and_receipts_are_read_only_and_explicitly_limited():void{
  $r=Release53Check::theme();$this->assertNotContains(false,$r['checks']);$this->assertCount(56,$r['declared_solid_pairs']);$this->assertFalse($r['rendered_computed_colours_measured']);$this->assertFalse($r['browser_screenshots_verified']);foreach(['learning_tasks','portal_operations_events','centre_manager_grants']as$t)$this->assertDatabaseCount($t,0);
 }
 public function test_theme_receipt_detects_asset_damage_then_recovers():void{
  $file=public_path('assets/theme53.css');$original=file_get_contents($file);try{file_put_contents($file,$original.'/* altered */');$this->assertFalse(Release53Check::theme()['checks']['receipt:public/assets/theme53.css']);}finally{file_put_contents($file,$original);}$this->assertTrue(Release53Check::theme()['checks']['receipt:public/assets/theme53.css']);
 }
 public function test_full_read_only_release_check_preserves_target_acceptance_boundary():void{
  $r=Release53Check::report();$this->assertNotSame('needs_attention',$r['status']);$this->assertSame('53',$r['pack']);$this->assertTrue($r['theme_only']);$this->assertFalse($r['actual_target_acceptance']);$this->assertFalse($r['writes_performed']);$this->artisan('gta:release-check53')->assertExitCode(0);
 }
}
