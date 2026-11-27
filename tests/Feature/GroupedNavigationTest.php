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
 public function test_timetable_and_register_tools_are_grouped_apart_from_training_tools(): void
 {
     $user = User::factory()->create(['role' => 'admin']);
     $menu = PortalNavigation::build($user, $this->requestFor('timetable-duties.flexible'));
     $groups = collect($menu['groups'])->keyBy('id');
     $this->assertTrue($groups['timetable-registers']['open']);
     $this->assertFalse($groups['training']['open']);
     $this->assertContains('Timetable', array_column($groups['timetable-registers']['links'], 'label'));
     $this->assertContains('Register changes', array_column($groups['timetable-registers']['links'], 'label'));
     $this->assertContains('Maths and English', array_column($groups['training']['links'], 'label'));
 }

 private function links(string $html):array {
  $dom=new \DOMDocument();$dom->loadHTML($html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$links=[];foreach($dom->getElementsByTagName('a')as$link){$links[]=$link->getAttribute('href');}sort($links);return $links;
 }
 private function parity(User $u):void {
  $this->actingAs($u);$this->app->instance('request',$this->requestFor('help.index'));view()->addNamespace('nav_baseline',base_path('tests/Fixtures'));
  $old=view('nav_baseline::navigation-pack17')->render();$new=view('navigation.menu')->render();$expected=$this->links($old);if(in_array($u->role,['admin','ceo','tutor'],true)){$expected[]=route('session-operations.index');sort($expected);}if(in_array($u->role,['admin','ceo'],true)){$expected[]=route('intakes.index');sort($expected);}if(\App\Support\StaffHandover::recipientAllowed($u)){$expected[]=route('incoming-handovers.index');sort($expected);}if(in_array($u->role,['admin','ceo'],true)){$expected[]=route('staff-handovers.index');sort($expected);}if(in_array($u->role,['admin','ceo'],true)){$expected[]=route('acceptance.index');sort($expected);}if(in_array($u->role,['admin','ceo'],true)){$expected[]=route('receipt-operations.index');sort($expected);}if(\App\Support\LeaverDestinations::allowed($u)){$expected[]=route('destinations.index');sort($expected);}if(in_array($u->role,['apprentice','employer'],true)||\App\Support\SupportAccess::team($u)){$expected[]=route('workplace-support.index');sort($expected);}if($u->role==='apprentice'){$expected[]=route('alerts.index');$expected[]=route('experience.home');$expected[]=route('getting-started.index');sort($expected);}if($u->role==='apprentice'||\App\Support\ApprenticeExperience::queueAllowed($u)){$expected[]=route('experience.index');sort($expected);}if(\App\Support\StaffAttention::allowed($u)){$expected[]=route('attention.index');sort($expected);}if($u->role==='employer'){$expected[]=route('employer-start.index');$expected[]=route('employer-actions.index');sort($expected);}if(in_array($u->role,['admin','ceo'],true)){$expected[]=route('employer-accounts.index');$expected[]=route('apprentice-onboarding.index');sort($expected);}if(in_array($u->role,['admin','ceo','tutor'],true)){$expected[]=route('gateway.index');sort($expected);}if($u->role==='apprentice'){$expected[]=route('apprentice-reminders.index');sort($expected);}if(in_array($u->role,['admin','ceo'],true)){$expected[]=route('apprentice-notifications.index');sort($expected);}if(in_array($u->role,['admin','ceo','tutor','apprentice','employer'],true)||StaffDuties::has($u,'mentor')){$expected[]=route('review-appointments.index');$expected[]=route('electronic-reviews.index');sort($expected);}if(\App\Support\CentrePlanning::staff($u)){$expected[]=route('centre-planning.index');sort($expected);}if(in_array($u->role,['admin','ceo'],true)){$expected[]=route('account-operations.index');}sort($expected);if(\App\Support\SavedPortalWork::allowed($u)){$expected[]=route('saved-work.index');}$expected[]=route('quick-actions.index');if(in_array($u->role,['admin','ceo','tutor'],true)){$expected[]=route('record-checks.index');}sort($expected);if(in_array($u->role,['admin','ceo'],true)){$expected[]=route('communications.index');}if(\App\Support\ReviewReminders::allowed($u)){$expected[]=route('review-workspace.index');}if($u->role==='apprentice'){$expected[]=route('calendar.index');$expected[]=route('communications.own');}sort($expected);if(\App\Support\CentreManagerAccess::workspace($u)){$expected[]=route('centre-oversight.index');}if(\App\Support\CentreFollowups::inbox($u)){$expected[]=route('centre-followups.index');}if(\App\Support\LearningAccess::manager($u)){$expected[]=route('centre-access.index');}if(\App\Support\LearningAccess::manager($u)){$expected[]=route('retention.admin');}if(\Illuminate\Support\Facades\Gate::forUser($u)->allows('view-employer-resources')){$expected[]=route('employer-resources.index');}if(in_array($u->role,['admin','ceo','tutor','apprentice','employer','mentor'],true)||StaffDuties::has($u,'mentor')||($u->role==='manager'&&\App\Support\CentreManagerAccess::workspace($u))){$expected[]=route('progress66.index');}if(in_array($u->role,['admin','ceo','tutor','apprentice'],true)||\App\Support\SupportAccess::team($u)){$expected[]=route('reports.index');}if (in_array($u->role,['admin','ceo'],true)) {
   // Creation actions are on Home and their list screens, not duplicated in the sidebar.
  }
  if (\App\Support\ReviewReminders::allowed($u)) {
   $expected = array_diff($expected, [route('review-workspace.index'),route('review-appointments.index'),route('electronic-reviews.index')]);
  }
  $expected = array_values(array_diff($expected, [route('support-review-reminders.index')]));
  if (\App\Support\ReportDirectory::links($u) && !in_array(route('reports.index'), $expected, true)) {
   $expected[] = route('reports.index');
  }
  if(in_array($u->role,['tutor','apprentice'],true)){$expected[]=route('weekly-retention.reminders');}
  if (!in_array($u->role, ['admin', 'ceo', 'tutor'], true)
      && (StaffDuties::has($u, 'maths_tutor') || StaffDuties::has($u, 'english_tutor'))) {
   $expected[] = route('marking.index');
  }
  sort($expected);$this->assertSame($expected,$this->links($new),'Unexpected href inventory changed for '.$u->role);
 }
 public function test_daily_and_secondary_navigation_destinations_for_all_primary_roles():void {
  foreach(['admin','ceo','tutor','apprentice','employer','staff','manager','mentor','functional_skills_tutor','careers_adviser','inclusion_lead','inclusion_member','unassigned']as$role){$this->parity(User::factory()->create(['role'=>$role,'subject_scope'=>$role==='functional_skills_tutor'?'both':null]));}
 }
 public function test_added_duties_preserve_links_and_show_only_relevant_sections():void {
  $admin=User::factory()->create(['role'=>'admin']);$u=User::factory()->create(['role'=>'staff']);StaffDuties::save($u,$admin,$admin->auth_version,$u->record_version,0,['careers_adviser'=>true,'mentor'=>true,'maths_tutor'=>true]);$this->parity($u->fresh());
  $menu=PortalNavigation::build($u->fresh(),$this->requestFor('em.index'));$groups=collect($menu['groups'])->keyBy('id');$this->assertArrayNotHasKey('administration',$groups->all());$this->assertArrayNotHasKey('system',$groups->all());$this->assertTrue($groups['training']['open']);
  $labels=collect($groups['training']['links'])->pluck('label')->all();$this->assertContains('Maths and English',$labels);$this->assertNotContains('Work to mark',$labels);$this->assertContains('Work to mark',array_column($menu['primary'],'label'));
 }
 public function test_empty_groups_are_omitted_and_help_stays_outside_disclosures():void {
  $u=User::factory()->create(['role'=>'apprentice']);$menu=PortalNavigation::build($u,$this->requestFor('help.index'));
  foreach($menu['groups']as$g){$this->assertNotEmpty($g['links']);$this->assertFalse($g['open']);}
  $ids=array_column($menu['groups'],'id');$this->assertNotContains('administration',$ids);$this->assertNotContains('system',$ids);$this->assertNotContains('staff-quality',$ids);$this->assertSame('Help and how to',$menu['help']['label']);$this->assertTrue($menu['help']['current']);
 }
 public function test_current_task_marks_the_primary_apprentice_link():void {
  $u=User::factory()->create(['role'=>'admin']);$menu=PortalNavigation::build($u,$this->requestFor('tasks.show'));
  $current=collect($menu['primary'])->where('current',true)->pluck('label')->all();
  $this->assertSame(['Apprentices'],array_values($current));
  $this->assertEmpty(array_filter($menu['groups'],fn($g)=>$g['open']));
 }
 public function test_directory_kind_support_permissions_and_questionnaire_setup_have_unique_groups():void {
  $u=User::factory()->create(['role'=>'admin']);foreach([['directories.show',['kind'=>'staff'],'Staff'],['support.permissions',[],'Support permissions'],['questionnaires.configuration',[],'Questionnaire link setup']]as[$route,$params,$label]) {
   $menu=PortalNavigation::build($u,$this->requestFor($route,$params));$open=array_values(array_filter($menu['groups'],fn($g)=>$g['open']));$this->assertCount(1,$open);$this->assertSame('administration',$open[0]['id']);$current=array_values(array_filter($open[0]['links'],fn($l)=>$l['current']));$this->assertSame([$label],array_column($current,'label'));
  }
 }
 public function test_account_and_subject_subpages_open_the_correct_section():void {
  $u=User::factory()->create(['role'=>'admin']);foreach([['topics.show','training'],['qualifications.edit','training'],['account.email.confirm','account'],['curriculum-sources.record','staff-quality'],['evidence-restore.show','system']]as[$name,$expected]) {
   $menu=PortalNavigation::build($u,$this->requestFor($name));$open=array_values(array_filter($menu['groups'],fn($g)=>$g['open']));$this->assertCount(1,$open);$this->assertSame($expected,$open[0]['id']);
  }
 }
 public function test_rendered_markup_has_native_disclosures_current_section_and_no_empty_headers():void {
  $u=User::factory()->create(['role'=>'admin']);$this->actingAs($u);$this->app->instance('request',$this->requestFor('marking.index'));$html=view('navigation.menu')->render();
  $dom=new \DOMDocument();$dom->loadHTML($html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$xpath=new \DOMXPath($dom);
  $this->assertSame(1,$xpath->query('//nav/details')->length);
  $this->assertSame(0,$xpath->query('//details//details | //details[@open]')->length);
  $this->assertSame(1,$xpath->query('//a[@aria-current="page"]')->length);
  $this->assertSame(0,$xpath->query('//section[@data-navigation-group][not(h2) or not(.//a)]')->length);
  $this->assertSame(1,$xpath->query('//nav/a[contains(@class,"navigation-help")]')->length);
 }
 public function test_role_shortcuts_are_direct_and_the_current_secondary_page_is_named(): void
 {
  $admin = User::factory()->create(['role'=>'admin']);
  $tutor = User::factory()->create(['role'=>'tutor']);
  $apprentice = User::factory()->create(['role'=>'apprentice']);
  $employer = User::factory()->create(['role'=>'employer']);
  $skills = User::factory()->create(['role'=>'functional_skills_tutor', 'subject_scope'=>'both']);

  foreach ([
   [$admin, ['Needs attention','Work today','Apprentices','Apprentice register','Reports']],
   [$tutor, ['Needs attention','Work today','Apprentices','Apprentice register','Work to mark']],
   [$apprentice, ['My next step','My progress','My calendar','Review signing']],
   [$employer, ['My employer actions','View progress','Review appointments','Review signing']],
   [$skills, ['Maths and English','Work to mark']],
  ] as [$actor, $expected]) {
   $menu = PortalNavigation::forDisplay($actor, $this->requestFor('help.index'));
   $this->assertSame($expected, $menu['daily']->pluck('label')->all());
  }

  $page = $this->actingAs($admin)->get(route('task-library.index'))->assertOk()
   ->assertSeeInOrder(['More tools','Task library'])->assertDontSee('id="navigation-toggle"', false);
  $dom = new \DOMDocument();
  $dom->loadHTML($page->getContent(), LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
  $xpath = new \DOMXPath($dom);
  $this->assertSame('Task library', trim($xpath->query('//nav[@id="portal-navigation"]//a[@aria-current="page"]')->item(0)->textContent));
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
