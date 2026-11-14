<?php
namespace Tests\Feature;

use App\Models\{StaffDevelopmentProfile as Profile,User};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class StaffFollowupTest extends TestCase
{
    use LazilyRefreshDatabase;
    protected function setUp(): void {parent::setUp();$this->travelTo(\Carbon\Carbon::parse('2026-11-13 12:00:00'));}
    private function actor(string $role='manager',array $fields=[]): User {return User::factory()->create(['role'=>$role,...$fields]);}
    private function action(string $id='action-1',array $fields=[]): array {return ['id'=>$id,'title'=>'PRIVATE ACTION TITLE','measure'=>'PRIVATE SUCCESS MEASURE','reason'=>'PRIVATE REASON','support'=>'PRIVATE SUPPORT','due'=>'2026-11-12','status'=>'In progress','updates'=>[],'completed'=>null,...$fields];}
    private function schedule(string $id='review-1',array $fields=[]): array {return ['id'=>$id,'kind'=>'appraisal','due'=>'2026-11-13','status'=>'Planned','completed_date'=>null,'entry_id'=>null,'history'=>[],...$fields];}
    private function profile(User $person,?User $manager=null,array $values=[]): Profile
    {
        $p=Profile::create(['user_id'=>$person->id,'payload'=>['entries'=>[],'actions'=>[],'schedules'=>[],'months'=>[],'impacts'=>[],...$values]]);$p->manager_id=$manager?->id;$p->save();return $p;
    }
    public function test_actions_counts_overlap_and_missing_or_impossible_dates_are_not_overdue(): void
    {
        $u=$this->actor('tutor');$m=$this->actor();$this->profile($u,$m,['actions'=>[$this->action('late',['status'=>'Ready for manager check']),$this->action('today',['due'=>'2026-11-13']),$this->action('missing',['due'=>null]),$this->action('invalid',['due'=>'2026-02-30']),$this->action('done',['status'=>'Completed'])]]);$r=$this->actingAs($m)->get('/staff-development')->assertOk();$c=$r->viewData('actionCounts');$this->assertSame(4,$c['all']);$this->assertSame(1,$c['overdue']);$this->assertSame(1,$c['today']);$this->assertSame(1,$c['checking']);$this->assertSame(2,$c['dates']);$this->get('/staff-development?action_state=checking')->assertViewHas('actionRows',fn($rows)=>$rows->total()===1);
    }
    public function test_reviews_exclude_completed_and_cancelled_and_keep_unknown_kind_as_a_check(): void
    {
        $u=$this->actor('tutor');$m=$this->actor();$this->profile($u,$m,['schedules'=>[$this->schedule('late',['due'=>'2026-11-12']),$this->schedule('today'),$this->schedule('future',['due'=>'2026-11-14']),$this->schedule('blank',['due'=>'']),$this->schedule('done',['status'=>'Completed']),$this->schedule('cancelled',['status'=>'Cancelled']),$this->schedule('unknown',['kind'=>'unknown'])]]);$r=$this->actingAs($m)->get('/staff-development')->assertOk();$this->assertSame(['all'=>5,'overdue'=>1,'today'=>1,'upcoming'=>1,'dates'=>1,'checks'=>1],$r->viewData('reviewCounts'));$this->assertSame(2,$r->viewData('summary')[$u->id]['due_reviews']);
    }
    public function test_employee_line_manager_and_ceo_scope_do_not_grant_ordinary_admin_global_hr_access(): void
    {
        $u=$this->actor('tutor');$m=$this->actor();$outside=$this->actor('staff');$this->profile($u,$m,['actions'=>[$this->action()]]);$this->profile($outside,null,['actions'=>[$this->action('foreign')]]);$this->actingAs($u)->get('/staff-development')->assertOk()->assertSee($u->name)->assertDontSee($outside->name);$this->actingAs($m)->get('/staff-development')->assertOk()->assertSee($u->name)->assertDontSee($outside->name);$this->actingAs($this->actor('admin'))->get('/staff-development')->assertOk()->assertDontSee($u->name);$this->actingAs($this->actor('ceo'))->get('/staff-development')->assertOk()->assertSee($u->name)->assertSee($outside->name);
    }
    public function test_reassignment_removes_old_manager_queues_and_source_access(): void
    {
        $u=$this->actor('tutor');$m=$this->actor();$p=$this->profile($u,$m,['actions'=>[$this->action()],'schedules'=>[$this->schedule()]]);$this->actingAs($m)->get('/staff-development')->assertViewHas('actionCounts',fn($c)=>$c['all']===1);$p->manager_id=$this->actor()->id;$p->save();$this->get('/staff-development')->assertViewHas('actionCounts',fn($c)=>$c['all']===0)->assertViewHas('reviewCounts',fn($c)=>$c['all']===0);$this->get('/staff-development/'.$u->id.'?view=manager')->assertForbidden();
    }
    public function test_private_text_is_absent_from_queues_and_employee_entry_count_remains_visibility_scoped(): void
    {
        $u=$this->actor('tutor');$m=$this->actor();$this->profile($u,$m,['entries'=>[['kind'=>'note','shared'=>false,'title'=>'PRIVATE ENTRY TITLE'],['kind'=>'cpd','shared'=>false]],'actions'=>[$this->action()],'schedules'=>[$this->schedule()]]);foreach([$u,$m] as $viewer){$r=$this->actingAs($viewer)->get('/staff-development')->assertOk();foreach(['PRIVATE ACTION TITLE','PRIVATE SUCCESS MEASURE','PRIVATE REASON','PRIVATE SUPPORT','PRIVATE ENTRY TITLE'] as $text){$r->assertDontSee($text);$this->assertStringNotContainsString($text,json_encode($r->viewData('actionRows')->items()));}$this->assertSame($viewer->id===$u->id?1:2,$r->viewData('summary')[$u->id]['entries']);}
    }
    public function test_staff_search_and_active_selection_apply_to_all_queues_without_cancelling_inactive_work(): void
    {
        $m=$this->actor();$u=$this->actor('tutor',['name'=>'Fictional inactive colleague','active'=>false]);$p=$this->profile($u,$m,['actions'=>[$this->action()],'schedules'=>[$this->schedule()]]);$before=$p->fresh()->getAttributes();$this->actingAs($m)->get('/staff-development?search=inactive&staff_state=active')->assertViewHas('actionCounts',fn($c)=>$c['all']===0);$this->get('/staff-development?search=inactive&staff_state=inactive')->assertViewHas('actionCounts',fn($c)=>$c['all']===1)->assertViewHas('reviewCounts',fn($c)=>$c['all']===1);$this->assertSame($before,$p->fresh()->getAttributes());
    }
    public function test_three_paginators_clamp_independently_and_keep_filters(): void
    {
        $u=$this->actor('tutor',['name'=>'Queue colleague']);$m=$this->actor();$actions=[];$schedules=[];for($i=0;$i<23;$i++){$actions[]=$this->action('a-'.$i);$schedules[]=$this->schedule('r-'.$i);}$this->profile($u,$m,['actions'=>$actions,'schedules'=>$schedules]);$r=$this->actingAs($m)->get('/staff-development?search=Queue&action_state=overdue&action_page=99&review_page=1&staff_page=99')->assertOk();$this->assertSame(2,$r->viewData('actionRows')->currentPage());$this->assertCount(3,$r->viewData('actionRows')->items());$this->assertSame(1,$r->viewData('reviewRows')->currentPage());$this->assertSame(1,$r->viewData('people')->currentPage());$this->assertStringContainsString('action_state=overdue',$r->viewData('actionRows')->previousPageUrl());
    }
    public function test_exact_action_and_review_anchors_open_existing_authorised_source_records(): void
    {
        $u=$this->actor('tutor');$m=$this->actor();$this->profile($u,$m,['actions'=>[$this->action()],'schedules'=>[$this->schedule()]]);$action='staff-action-'.hash('sha256','action-1');$review='staff-review-'.hash('sha256','review-1');$this->actingAs($m)->get('/staff-development')->assertOk()->assertSee('#'.$action,false)->assertSee('#'.$review,false);$this->get('/staff-development/'.$u->id.'?view=manager')->assertOk()->assertSee('id="'.$action.'"',false)->assertSee('id="'.$review.'"',false);
    }
    public function test_source_dates_do_not_show_invalid_calendar_dates_as_overdue(): void
    {
        $u=$this->actor('tutor');$m=$this->actor();$this->profile($u,$m,['actions'=>[$this->action('invalid',['due'=>'2026-02-30'])],'schedules'=>[$this->schedule('invalid',['due'=>'2026-02-30'])]]);$this->actingAs($m)->get('/staff-development/'.$u->id.'?view=manager')->assertOk()->assertSee('Check source date')->assertDontSee('Overdue · In progress');
    }
    public function test_missing_profiles_and_empty_search_do_not_establish_completed_staff_development(): void
    {
        $u=$this->actor('tutor');$r=$this->actingAs($u)->get('/staff-development?staff_state=unrecorded')->assertOk()->assertSee('No development record — summary unknown');$this->assertSame(1,$r->viewData('staffCounts')['unrecorded']);$this->get('/staff-development?search=NO-MATCH-STAFF')->assertViewHas('actionCounts',fn($c)=>$c['all']===0)->assertViewHas('reviewCounts',fn($c)=>$c['all']===0);
    }
    public function test_invalid_query_shapes_and_unsupported_roles_do_not_broaden_private_scope(): void
    {
        $this->actingAs($this->actor());foreach(['search[]=x','staff_state=invalid','action_state=invalid','review_state=invalid','staff_page=0','action_page=0','review_page=0'] as $q){$this->getJson('/staff-development?'.$q)->assertUnprocessable();}foreach(['apprentice','employer'] as $role){$this->actingAs($this->actor($role))->get('/staff-development')->assertForbidden();}
    }
    public function test_reads_preserve_profiles_versions_and_staff_audit_rows_and_flag_unreadable_collections(): void
    {
        $u=$this->actor('tutor');$m=$this->actor();$p=$this->profile($u,$m,['actions'=>'invalid source list']);$before=$p->fresh()->getAttributes();$r=$this->actingAs($m)->get('/staff-development?action_state=checks')->assertOk();$this->assertSame(1,$r->viewData('actionRows')->total());$this->assertSame($before,$p->fresh()->getAttributes());$this->assertDatabaseCount('staff_development_events',0);
    }
}
