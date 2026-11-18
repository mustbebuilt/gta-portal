<?php
namespace Tests\Feature;

use App\Models\{Apprentice,User};
use App\Support\{ApprenticeRoadmap67 as Road,ProgressDashboard66 as Dash};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ApprenticeRoadmap67Test extends TestCase
{
 use LazilyRefreshDatabase;
 public function test_projection_discards_sensitive_types_and_all_narrative_fields():void{
  $events=Road::project([
   ['type'=>'safeguarding','date'=>'2026-11-01','title'=>'PRIVATE-SAFEGUARD67'],
   ['type'=>'support','date'=>'2026-11-01','title'=>'PRIVATE-SUPPORT67'],
   ['type'=>'employer','date'=>'2026-11-02','title'=>'PRIVATE-TITLE67','detail'=>'PRIVATE-NOTE67','reason'=>'PRIVATE-REASON67','url'=>'/private/67'],
  ],'2026-11-17');
  $this->assertCount(1,$events);$this->assertSame('Employer changed',$events[0]['title']);
  $this->assertSame(['date','type','category','title','status','block'],array_keys($events[0]));
  $this->assertStringNotContainsString('PRIVATE',json_encode($events));
 }
 public function test_invalid_dates_and_unconfirmed_future_achievements_are_withheld():void{
  $events=Road::project([['type'=>'start','date'=>'2026-02-30'],['type'=>'completion','date'=>'2027-01-01'],['type'=>'review_booking','date'=>'2026-11-24'],['type'=>'planned_end','date'=>'2027-08-31']],'2026-11-17');
  $this->assertCount(2,$events);$this->assertSame(['Planned','Planned'],array_column($events,'status'));
 }
 public function test_marker_follows_recorded_blocks_and_does_not_advance_with_time():void{
  $blocks=Dash::blocks(['Approved'=>12]);$a=Road::compose($blocks,[],'2026-11-17');$b=Road::compose($blocks,[],'2027-11-17');
  $this->assertSame(3,$a['current']);$this->assertSame($a['current'],$b['current']);$this->assertCount(1,array_filter($a['stops'],fn($s)=>$s['pulse']));
 }
 public function test_unknown_position_does_not_get_an_invented_marker_and_achievement_stops_animation():void{
  $unknown=Road::compose(Dash::blocks(null),[],'2026-11-17');$this->assertNull($unknown['current']);
  $done=Road::compose(Dash::blocks(['Approved'=>50]),[['type'=>'completion','date'=>'2026-11-17']],'2026-11-17',true);
  $this->assertSame(12,$done['current']);$this->assertCount(0,array_filter($done['stops'],fn($s)=>$s['pulse']));
 }
 public function test_demo_journey_exports_are_labelled_and_share_the_safe_event_projection():void{
  $u=User::factory()->create(['role'=>'admin']);$this->actingAs($u);
  $j=$this->get('/progress-dashboard/demo/apprentice/export/json?apprentice=DEMO-01&section=journey')->assertOk()->json();
  $this->assertSame('Demonstration — fictional data',$j['label']);$this->assertSame(5,$j['roadmap']['current']);
  $csv=$this->get('/progress-dashboard/demo/apprentice/export/csv?apprentice=DEMO-01&section=journey')->assertOk()->getContent();
  $this->assertStringContainsString('Employer changed',$csv);$this->assertStringNotContainsString('Support',$csv);
  $this->assertSame(count($j['roadmap']['events'])+2,count(array_filter(explode("\n",trim($csv)))));
 }
 public function test_foreign_learner_and_external_roles_cannot_gain_journey_export_by_query():void{
  $u=User::factory()->create(['role'=>'apprentice']);Apprentice::factory()->create(['user_id'=>$u->id]);$other=Apprentice::factory()->create();
  $this->actingAs($u)->get('/progress-dashboard/export/json?section=journey&apprentice='.$other->id)->assertNotFound();
  $external=User::factory()->create(['role'=>'employer']);$this->actingAs($external)->get('/progress-dashboard/export/json?section=journey')->assertNotFound();
 }
 public function test_the_live_journey_export_contains_no_workplace_achievement_notes():void{
  $u=User::factory()->create(['role'=>'apprentice']);$a=Apprentice::factory()->create(['user_id'=>$u->id]);
  \App\Models\ApprenticeExperienceEntry::create(['apprentice_id'=>$a->id,'requester_id'=>$u->id,'request_token'=>(string)\Illuminate\Support\Str::uuid(),'kind'=>'achievement','title'=>'PRIVATE-TITLE67','detail'=>'PRIVATE-DETAIL67','response'=>'PRIVATE-RESPONSE67','state'=>'Approved','happened_on'=>today()->subDay(),'version'=>1,'history'=>[]]);
  $this->actingAs($u)->get('/progress-dashboard/export/json?section=journey')->assertOk()->assertDontSee('PRIVATE-TITLE67')->assertDontSee('PRIVATE-DETAIL67')->assertDontSee('PRIVATE-RESPONSE67')->assertSee('Workplace achievement confirmed');
 }
}
