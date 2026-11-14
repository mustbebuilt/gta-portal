<?php
namespace Tests\Feature;
use App\Models\{Apprentice,StaffDuty,User};
use App\Support\{AssignmentChecks,StaffDuties};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AssignmentChecksTest extends TestCase
{
 use LazilyRefreshDatabase;
 private function fixture():array {
  $admin=User::factory()->create(['role'=>'admin']);$tutor=User::factory()->create(['role'=>'tutor','name'=>'Fictional Main Tutor']);
  $off=User::factory()->create(['role'=>'mentor','active'=>false,'name'=>'Fictional Inactive Mentor']);
  $staff=User::factory()->create(['role'=>'staff','name'=>'Fictional Added Duties']);
  $a=Apprentice::factory()->create(['reference'=>'NEEDS-CHECK','tutor_id'=>$tutor->id,'mentor_id'=>$off->id,'careers_adviser_id'=>$staff->id,'maths_tutor_id'=>$staff->id,'english_tutor_id'=>null]);
  return [$admin,$tutor,$off,$staff,$a];
 }
 public function test_only_active_admin_ceo_can_open_the_report():void {
  $this->get('/assignment-checks')->assertRedirect('/login');
  foreach(['tutor','staff','manager','mentor','functional_skills_tutor','careers_adviser','inclusion_lead','inclusion_member','apprentice','employer'] as $role) {
   $u=User::factory()->create(['role'=>$role]);$this->actingAs($u)->get('/assignment-checks')->assertForbidden();
  }
  foreach(['admin','ceo'] as $role) {$this->actingAs(User::factory()->create(['role'=>$role]))->get('/assignment-checks')->assertOk();}
  $this->actingAs(User::factory()->create(['role'=>'admin','active'=>false]))->get('/assignment-checks')->assertForbidden();
 }
 public function test_default_checks_distinguish_inactive_changed_and_unassigned():void {
  [$admin,$tutor,$off,$staff,$a]=$this->fixture();
  $r=$this->actingAs($admin)->get('/assignment-checks')->assertOk()->assertSee('NEEDS-CHECK')->assertSee('Inactive account')->assertSee('Role or subject duty needs checking')->assertSee('Not assigned');
  $this->assertSame(['apprentices'=>1,'eligible'=>1,'unassigned'=>1,'checks'=>3],$r->viewData('counts'));
  $this->assertStringContainsString('no-store',$r->headers->get('Cache-Control'));
  $this->assertSame('checks',$r->viewData('filters')['view']);
 }
 public function test_primary_and_additional_subject_duties_match_eligibility():void {
  [$admin,$tutor,$off,$staff,$a]=$this->fixture();
  $primary=User::factory()->create(['role'=>'functional_skills_tutor','subject_scope'=>'english']);
  $a->english_tutor_id=$primary->id;$a->save();
  StaffDuties::save($staff,$admin,$admin->auth_version,$staff->record_version,0,['careers_adviser'=>true,'maths_tutor'=>true]);
  $row=AssignmentChecks::snapshot()['rows']->firstWhere('apprentice.id',$a->id);
  $this->assertSame('eligible',$row['slots']['english_tutor']['position']);$this->assertSame('eligible',$row['slots']['maths_tutor']['position']);$this->assertSame('eligible',$row['slots']['careers_adviser']['position']);
  $a->maths_tutor_id=$primary->id;$a->save();$row=AssignmentChecks::snapshot()['rows']->first();$this->assertSame('changed',$row['slots']['maths_tutor']['position']);
 }
 public function test_blank_optional_assignments_are_separate_from_attention_queue():void {
  [$admin,$tutor]=$this->fixture();$a=Apprentice::factory()->create(['reference'=>'OPTIONAL-BLANK','tutor_id'=>$tutor->id]);
  $this->actingAs($admin)->get('/assignment-checks')->assertDontSee('OPTIONAL-BLANK');
  $r=$this->get('/assignment-checks?view=unassigned')->assertOk()->assertSee('OPTIONAL-BLANK')->assertSee('not an overdue task');
  $this->assertSame(2,$r->viewData('rows')->total());
  $this->get('/assignment-checks?view=all')->assertSee('OPTIONAL-BLANK');
 }
 public function test_person_role_and_search_filters_have_consistent_counts():void {
  [$admin,$tutor,$off,$staff,$a]=$this->fixture();Apprentice::factory()->create(['reference'=>'OTHER-ASSIGNMENT','tutor_id'=>$tutor->id]);
  $r=$this->actingAs($admin)->get('/assignment-checks?view=all&staff='.$staff->id.'&role=maths_tutor&search=Fictional%20Added')->assertOk()->assertSee('NEEDS-CHECK')->assertDontSee('OTHER-ASSIGNMENT');
  $this->assertSame(['apprentices'=>1,'eligible'=>0,'unassigned'=>0,'checks'=>1],$r->viewData('counts'));
  $this->assertSame(['maths_tutor'],array_keys($r->viewData('rows')->items()[0]['slots']));
  $this->get('/assignment-checks?view=all&search=NEEDS-CHECK')->assertSee('NEEDS-CHECK')->assertDontSee('OTHER-ASSIGNMENT');
 }
 public function test_pagination_keeps_all_matching_counts_and_filters():void {
  $admin=User::factory()->create(['role'=>'admin']);$t=User::factory()->create(['role'=>'tutor']);
  for($i=1;$i<=25;$i++) {Apprentice::factory()->create(['reference'=>'PAGE-'.sprintf('%02d',$i),'last_name'=>sprintf('%02d',$i),'tutor_id'=>$t->id]);}
  $r=$this->actingAs($admin)->get('/assignment-checks?view=all&role=tutor&page=1')->assertOk();
  $this->assertCount(20,$r->viewData('rows')->items());$this->assertSame(25,$r->viewData('rows')->total());$this->assertSame(25,$r->viewData('counts')['eligible']);$r->assertSee('role=tutor');
  $r=$this->get('/assignment-checks?view=all&role=tutor&page=2')->assertOk();$this->assertCount(5,$r->viewData('rows')->items());$this->assertSame(25,$r->viewData('counts')['eligible']);
  $r=$this->get('/assignment-checks?view=all&role=tutor&page=999')->assertOk();$this->assertSame(2,$r->viewData('rows')->currentPage());
 }
 public function test_invalid_filters_are_rejected_and_search_is_literal_escaped():void {
  [$admin,$tutor,$off,$staff,$a]=$this->fixture();$staff->name='<script>alert(1)</script>';$staff->save();
  $this->actingAs($admin)->get('/assignment-checks?role=admin')->assertSessionHasErrors('role');
  $this->get('/assignment-checks?view=hack')->assertSessionHasErrors('view');$this->get('/assignment-checks?page=0')->assertSessionHasErrors('page');$this->get('/assignment-checks?staff=999999')->assertSessionHasErrors('staff');
  $this->get('/assignment-checks?view=all')->assertOk()->assertDontSee('<script>alert(1)</script>',false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;',false);
  $r=$this->get('/assignment-checks?view=all&search=%25')->assertOk();$this->assertSame(0,$r->viewData('rows')->total());
 }
 public function test_report_preserves_business_data_versions_grants_and_saved_assignments():void {
  [$admin,$tutor,$off,$staff,$a]=$this->fixture();$before=DB::table('apprentices')->get()->toArray();$accounts=DB::table('users')->get()->toArray();
  $this->actingAs($admin)->get('/assignment-checks?view=all')->assertOk();
  $this->assertEquals($before,DB::table('apprentices')->get()->toArray());$this->assertEquals($accounts,DB::table('users')->get()->toArray());$this->assertDatabaseCount('staff_duties',0);
  $this->post('/assignment-checks',[])->assertStatus(405);
 }
 public function test_fresh_check_reflects_deliberate_grant_revoke_and_reassignment():void {
  [$admin,$tutor,$off,$staff,$a]=$this->fixture();$this->actingAs($admin);
  $r=$this->get('/assignment-checks?role=maths_tutor');$this->assertSame(1,$r->viewData('rows')->total());
  StaffDuties::save($staff,$admin,$admin->auth_version,$staff->record_version,0,['maths_tutor'=>true]);
  $r=$this->get('/assignment-checks?role=maths_tutor');$this->assertSame(0,$r->viewData('rows')->total());$this->assertSame(1,$r->viewData('counts')['eligible']);
  StaffDuties::save($staff,$admin,$admin->auth_version,$staff->fresh()->record_version,1,[]);
  $r=$this->get('/assignment-checks?role=maths_tutor');$this->assertSame(1,$r->viewData('rows')->total());
  $a->maths_tutor_id=null;$a->save();$r=$this->get('/assignment-checks?role=maths_tutor');$this->assertSame(0,$r->viewData('rows')->total());$this->assertSame(1,$r->viewData('counts')['unassigned']);
 }
 public function test_snapshot_loads_only_assignment_identity_fields_in_fixed_query_count():void {
  $this->fixture();DB::enableQueryLog();$snapshot=AssignmentChecks::snapshot();$queries=DB::getQueryLog();DB::disableQueryLog();
  $this->assertCount(7,$queries);$attrs=array_keys($snapshot['rows']->first()['apprentice']->getAttributes());sort($attrs);
  $expected=['id','reference','first_name','last_name','tutor_id','careers_adviser_id','mentor_id','maths_tutor_id','english_tutor_id'];sort($expected);$this->assertSame($expected,$attrs);
  $this->assertSame(['id','name','role','active'],array_keys($snapshot['staff']->first()->getAttributes()));
 }
 public function test_setup_staff_list_and_staff_record_link_to_assignment_report():void {
  [$admin,$tutor]=$this->fixture();$this->actingAs($admin)->get('/portal-setup')->assertOk()->assertSee('/assignment-checks',false);
  $this->get('/directories/staff')->assertOk()->assertSee('/assignment-checks',false);
  $this->get('/directories/staff/'.$tutor->id)->assertOk()->assertSee('View this person’s apprentice assignments');
 }
}
