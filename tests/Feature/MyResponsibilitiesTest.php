<?php
namespace Tests\Feature;
use App\Models\{Apprentice,StaffDuty,User};
use App\Support\{MyResponsibilities,StaffDuties};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class MyResponsibilitiesTest extends TestCase
{
 use LazilyRefreshDatabase;
 private function fixture():array {
  $admin=User::factory()->create(['role'=>'admin']);$staff=User::factory()->create(['role'=>'tutor']);
  $both=Apprentice::factory()->create(['reference'=>'MULTIPLE-OWN','tutor_id'=>$staff->id,'careers_adviser_id'=>$staff->id,'mentor_id'=>$staff->id,'maths_tutor_id'=>$staff->id,'english_tutor_id'=>$staff->id]);
  $career=Apprentice::factory()->create(['reference'=>'CAREER-OWN','careers_adviser_id'=>$staff->id]);$other=Apprentice::factory()->create(['reference'=>'OTHER-PRIVATE']);
  return [$admin,$staff,$both,$career,$other];
 }
 private function grant(User $staff,User $admin,array $flags):void {
  StaffDuties::save($staff,$admin,$admin->auth_version,$staff->fresh()->record_version,StaffDuty::find($staff->id)?->version??0,$flags);
 }
 public function test_staff_hub_requires_active_staff_or_management_and_authentication():void {
  $this->get('/my-responsibilities')->assertRedirect('/login');
  foreach(['apprentice','employer','unassigned']as$role){$this->actingAs(User::factory()->create(['role'=>$role]))->get('/my-responsibilities')->assertForbidden();}
  foreach(['admin','ceo','tutor','staff','manager','mentor','functional_skills_tutor','careers_adviser','inclusion_lead','inclusion_member']as$role){$this->actingAs(User::factory()->create(['role'=>$role]))->get('/my-responsibilities')->assertOk();}
  $this->actingAs(User::factory()->create(['role'=>'tutor','active'=>false]))->get('/my-responsibilities')->assertForbidden();
 }
 public function test_all_five_responsibilities_form_a_unique_person_union_with_separate_counts():void {
  [$admin,$staff,$both,$career]=$this->fixture();$this->grant($staff,$admin,['careers_adviser'=>true,'mentor'=>true,'maths_tutor'=>true,'english_tutor'=>true]);
  $r=$this->actingAs($staff->fresh())->get('/my-responsibilities')->assertOk()->assertSee('MULTIPLE-OWN')->assertSee('CAREER-OWN')->assertDontSee('OTHER-PRIVATE');
  $this->assertSame(2,$r->viewData('total'));$this->assertSame(['tutor'=>1,'careers_adviser'=>2,'mentor'=>1,'maths_tutor'=>1,'english_tutor'=>1],$r->viewData('counts'));$this->assertSame(2,$r->viewData('rows')->total());
  $this->assertStringContainsString('no-store',$r->headers->get('Cache-Control'));
 }
 public function test_ids_without_eligible_duties_never_reveal_other_assigned_names():void {
  [$admin,$staff,$both,$career]=$this->fixture();
  $r=$this->actingAs($staff)->get('/my-responsibilities')->assertOk()->assertSee('MULTIPLE-OWN')->assertDontSee('CAREER-OWN');$this->assertSame(['tutor'=>1],$r->viewData('counts'));
  $this->assertSame(['tutor'],array_keys($r->viewData('rows')->items()[0]['duties']));
  $this->get('/my-responsibilities?role=careers_adviser')->assertForbidden();
 }
 public function test_subject_only_extra_eligibility_does_not_offer_other_subject_or_main_tutor_links():void {
  [$admin,$staff,$both,$career]=$this->fixture();$staff->forceFill(['role'=>'staff'])->save();$this->grant($staff,$admin,['maths_tutor'=>true]);
  $r=$this->actingAs($staff->fresh())->get('/my-responsibilities')->assertOk()->assertSee('MULTIPLE-OWN')->assertDontSee('CAREER-OWN');$this->assertSame(['maths_tutor'=>1],$r->viewData('counts'));
  $duties=$r->viewData('rows')->items()[0]['duties'];$this->assertSame(['maths_tutor'],array_keys($duties));
  $url=MyResponsibilities::destination('maths_tutor',$both);$this->assertStringContainsString('subject=maths',$url);$this->get($url)->assertOk();
  $this->get('/my-responsibilities?role=english_tutor')->assertForbidden();$this->get('/apprentices/'.$both->id.'/maths-english?subject=english')->assertForbidden();
 }
 public function test_primary_fs_scope_and_extra_english_scope_both_work_with_real_links():void {
  [$admin,$staff,$both]=$this->fixture();$staff->forceFill(['role'=>'functional_skills_tutor','subject_scope'=>'maths'])->save();$this->grant($staff,$admin,['english_tutor'=>true]);
  $r=$this->actingAs($staff->fresh())->get('/my-responsibilities')->assertOk();$this->assertSame(['maths_tutor'=>1,'english_tutor'=>1],$r->viewData('counts'));
  $this->get(MyResponsibilities::destination('english_tutor',$both))->assertOk();$this->get(MyResponsibilities::destination('maths_tutor',$both))->assertOk();
 }
 public function test_destination_links_use_the_actual_role_modules_and_do_not_grant_access():void {
  [$admin,$staff,$both,$career]=$this->fixture();$this->grant($staff,$admin,['careers_adviser'=>true,'mentor'=>true]);$this->actingAs($staff->fresh());
  foreach(['tutor','careers_adviser','mentor']as$role){$this->get(MyResponsibilities::destination($role,$both))->assertOk();$this->get(MyResponsibilities::destination($role))->assertOk();}
  $this->get('/apprentices/'.$career->id.'/learning')->assertForbidden();
 }
 public function test_no_duty_or_no_assignment_shows_help_without_a_false_completion_claim():void {
  [$admin,$staff]=$this->fixture();$staff->forceFill(['role'=>'staff'])->save();$r=$this->actingAs($staff)->get('/my-responsibilities')->assertOk()->assertSee('Ask your administrator')->assertDontSee('MULTIPLE-OWN');$this->assertSame(0,$r->viewData('total'));
  $this->grant($staff,$admin,['mentor'=>true]);$new=User::factory()->create(['role'=>'mentor']);$r=$this->actingAs($new)->get('/my-responsibilities')->assertOk()->assertSee('empty list does not mean the work is complete');$this->assertSame(['mentor'=>0],$r->viewData('counts'));
 }
 public function test_management_and_inclusion_do_not_expand_hub_personal_names_or_show_private_case_counts():void {
  [$admin,$staff]=$this->fixture();$r=$this->actingAs($admin)->get('/my-responsibilities')->assertOk()->assertSee('Assignment checks')->assertDontSee('MULTIPLE-OWN');$this->assertSame(0,$r->viewData('total'));
  $incl=User::factory()->create(['role'=>'inclusion_member']);$r=$this->actingAs($incl)->get('/my-responsibilities')->assertOk()->assertSee('Open Support worklist')->assertDontSee('OTHER-PRIVATE');$this->assertSame(0,$r->viewData('total'));$this->assertSame([],$r->viewData('counts'));
 }
 public function test_search_role_and_pagination_preserve_full_own_counts_and_unique_rows():void {
  $staff=User::factory()->create(['role'=>'tutor']);for($i=1;$i<=25;$i++){Apprentice::factory()->create(['reference'=>'OWN-'.sprintf('%02d',$i),'last_name'=>sprintf('%02d',$i),'tutor_id'=>$staff->id]);}
  $r=$this->actingAs($staff)->get('/my-responsibilities?role=tutor&page=2')->assertOk();$this->assertCount(5,$r->viewData('rows')->items());$this->assertSame(25,$r->viewData('counts')['tutor']);$this->assertSame(25,$r->viewData('rows')->total());
  $r=$this->get('/my-responsibilities?search=OWN-01')->assertOk();$this->assertSame(1,$r->viewData('rows')->total());$this->assertSame(25,$r->viewData('total'));
  $r=$this->get('/my-responsibilities?page=999')->assertOk();$this->assertSame(2,$r->viewData('rows')->currentPage());
 }
 public function test_other_user_query_cannot_switch_identity_and_inputs_are_validated_and_escaped():void {
  [$admin,$staff,$both]=$this->fixture();$both->first_name='<script>PRIVATE-INJECTION</script>';$both->save();
  $this->actingAs($staff)->get('/my-responsibilities?user='.$admin->id)->assertOk()->assertSee('MULTIPLE-OWN')->assertDontSee('OTHER-PRIVATE')->assertDontSee('<script>PRIVATE-INJECTION</script>',false)->assertSee('&lt;script&gt;PRIVATE-INJECTION&lt;/script&gt;',false);
  $this->get('/my-responsibilities?role=admin')->assertSessionHasErrors('role');$this->get('/my-responsibilities?page=0')->assertSessionHasErrors('page');$r=$this->get('/my-responsibilities?search=%25')->assertOk();$this->assertSame(0,$r->viewData('rows')->total());
 }
 public function test_revocation_preserves_records_and_removes_only_the_ended_scope():void {
  [$admin,$staff,$both,$career]=$this->fixture();$this->grant($staff,$admin,['careers_adviser'=>true,'mentor'=>true]);$before=DB::table('apprentices')->get()->toArray();$this->grant($staff,$admin,['mentor'=>true]);
  $r=$this->actingAs($staff->fresh())->get('/my-responsibilities')->assertOk()->assertDontSee('CAREER-OWN');$this->assertSame(['tutor'=>1,'mentor'=>1],$r->viewData('counts'));$this->assertEquals($before,DB::table('apprentices')->get()->toArray());
 }
 public function test_reads_are_business_read_only_and_never_load_unrelated_personal_data():void {
  [$admin,$staff]=$this->fixture();$before=DB::table('users')->get()->toArray();$apprentices=DB::table('apprentices')->get()->toArray();
  $summary=MyResponsibilities::summary($staff);$this->assertSame(['id','reference','first_name','last_name','tutor_id'],array_keys($summary['rows']->first()['apprentice']->getAttributes()));
  $this->actingAs($staff)->get('/my-responsibilities')->assertOk();$this->assertEquals($before,DB::table('users')->get()->toArray());$this->assertEquals($apprentices,DB::table('apprentices')->get()->toArray());$this->assertDatabaseCount('staff_duties',0);$this->post('/my-responsibilities')->assertStatus(405);
 }
}
