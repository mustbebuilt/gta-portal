<?php
namespace Tests\Feature;
use App\Models\User;
use App\Support\{PortalNavigation,StaffDuties};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Tests\TestCase;

class GroupedNavigationTest extends TestCase
{
 use LazilyRefreshDatabase;
 private function requestFor(string $name,array $parameters=[]):Request {
  $r=Request::create('/fictional-navigation-test');$route=new Route(['GET'],'/fictional-navigation-test',fn()=>null);$route->name($name)->bind($r);foreach($parameters as $key=>$value){$route->setParameter($key,$value);}$r->setRouteResolver(fn()=>$route);return $r;
 }
 private function links(string $html):array {
  $dom=new \DOMDocument();$dom->loadHTML($html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$links=[];foreach($dom->getElementsByTagName('a')as$link){$links[]=$link->getAttribute('href');}sort($links);return $links;
 }
 private function parity(User $u):void {
  $this->actingAs($u);$this->app->instance('request',$this->requestFor('help.index'));view()->addNamespace('nav_baseline',base_path('tests/fixtures'));
  $old=view('nav_baseline::navigation-pack17')->render();$new=view('navigation.menu')->render();$this->assertSame($this->links($old),$this->links($new),'Existing href inventory changed for '.$u->role);
 }
 public function test_every_existing_link_is_preserved_for_all_primary_roles():void {
  foreach(['admin','ceo','tutor','apprentice','employer','staff','manager','mentor','functional_skills_tutor','careers_adviser','inclusion_lead','inclusion_member','unassigned']as$role){$this->parity(User::factory()->create(['role'=>$role,'subject_scope'=>$role==='functional_skills_tutor'?'both':null]));}
 }
 public function test_added_duties_preserve_links_and_show_only_relevant_sections():void {
  $admin=User::factory()->create(['role'=>'admin']);$u=User::factory()->create(['role'=>'staff']);StaffDuties::save($u,$admin,$admin->auth_version,$u->record_version,0,['careers_adviser'=>true,'mentor'=>true,'maths_tutor'=>true]);$this->parity($u->fresh());
  $menu=PortalNavigation::build($u->fresh(),$this->requestFor('em.index'));$groups=collect($menu['groups'])->keyBy('id');$this->assertArrayNotHasKey('administration',$groups->all());$this->assertArrayNotHasKey('system',$groups->all());$this->assertTrue($groups['training']['open']);
  $labels=collect($groups['training']['links'])->pluck('label')->all();$this->assertContains('Maths and English',$labels);$this->assertNotContains('Marking worklist',$labels);
 }
 public function test_empty_groups_are_omitted_and_help_stays_outside_disclosures():void {
  $u=User::factory()->create(['role'=>'apprentice']);$menu=PortalNavigation::build($u,$this->requestFor('help.index'));
  foreach($menu['groups']as$g){$this->assertNotEmpty($g['links']);$this->assertFalse($g['open']);}
  $ids=array_column($menu['groups'],'id');$this->assertNotContains('administration',$ids);$this->assertNotContains('system',$ids);$this->assertNotContains('staff-quality',$ids);$this->assertSame('Help and how to',$menu['help']['label']);$this->assertTrue($menu['help']['current']);
 }
 public function test_current_task_page_opens_training_and_marks_only_one_ancestor():void {
  $u=User::factory()->create(['role'=>'admin']);$menu=PortalNavigation::build($u,$this->requestFor('tasks.show'));$open=array_values(array_filter($menu['groups'],fn($g)=>$g['open']));$this->assertCount(1,$open);$this->assertSame('training',$open[0]['id']);$current=array_values(array_filter($open[0]['links'],fn($l)=>$l['current']));$this->assertCount(1,$current);$this->assertSame('Apprentices',$current[0]['label']);
 }
 public function test_directory_kind_support_permissions_and_questionnaire_setup_have_unique_groups():void {
  $u=User::factory()->create(['role'=>'admin']);foreach([['directories.show',['kind'=>'staff'],'Staff'],['support.permissions',[],'Support permissions'],['questionnaires.configuration',[],'Questionnaire link setup']]as[$route,$params,$label]) {
   $menu=PortalNavigation::build($u,$this->requestFor($route,$params));$open=array_values(array_filter($menu['groups'],fn($g)=>$g['open']));$this->assertCount(1,$open);$this->assertSame('administration',$open[0]['id']);$current=array_values(array_filter($open[0]['links'],fn($l)=>$l['current']));$this->assertSame([$label],array_column($current,'label'));
  }
 }
 public function test_account_and_subject_subpages_open_the_correct_section():void {
  $u=User::factory()->create(['role'=>'admin']);foreach([['topics.show','training'],['qualifications.edit','training'],['account.email.confirm','account'],['reviews.create','reviews-careers'],['support-review-reminders.index','support'],['curriculum-sources.record','staff-quality'],['evidence-restore.show','system']]as[$name,$expected]) {
   $menu=PortalNavigation::build($u,$this->requestFor($name));$open=array_values(array_filter($menu['groups'],fn($g)=>$g['open']));$this->assertCount(1,$open);$this->assertSame($expected,$open[0]['id']);
  }
 }
 public function test_rendered_markup_has_native_disclosures_current_section_and_no_empty_headers():void {
  $u=User::factory()->create(['role'=>'admin']);$this->actingAs($u);$this->app->instance('request',$this->requestFor('marking.index'));$html=view('navigation.menu')->render();
  $dom=new \DOMDocument();$dom->loadHTML($html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$xpath=new \DOMXPath($dom);
  $this->assertSame(7,$xpath->query('//details[@data-navigation-group]')->length);$this->assertSame(1,$xpath->query('//details[@open]')->length);$this->assertSame('training',$xpath->query('//details[@open]')->item(0)->getAttribute('data-navigation-group'));
  $this->assertSame(1,$xpath->query('//a[@aria-current="page"]')->length);$this->assertSame(0,$xpath->query('//details[not(summary)]')->length);$this->assertSame(0,$xpath->query('//details[not(.//a)]')->length);$this->assertSame(1,$xpath->query('//nav/a[contains(@class,"navigation-help")]')->length);
 }
 public function test_inactive_account_does_not_build_navigation_and_guest_has_no_sidebar_links():void {
  $u=User::factory()->create(['role'=>'admin','active'=>false]);$this->assertSame(['primary'=>[],'groups'=>[],'help'=>null],PortalNavigation::build($u,$this->requestFor('help.index')));
  $this->get('/login')->assertOk()->assertDontSee('data-navigation-group=',false)->assertDontSee('id="portal-navigation"',false);
 }
 public function test_grouping_does_not_change_destination_authorisation():void {
  $u=User::factory()->create(['role'=>'tutor']);$this->actingAs($u)->get('/help')->assertOk()->assertSee('data-navigation-group="training"',false)->assertDontSee('data-navigation-group="administration"',false);
  $this->get('/portal-setup')->assertForbidden();$this->get('/directories/staff')->assertForbidden();$this->get('/reminder-mail')->assertForbidden();
 }
}
