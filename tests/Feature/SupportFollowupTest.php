<?php
namespace Tests\Feature;

use App\Models\{Apprentice,SupportCapability,SupportConcern,SupportPlan,User};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SupportFollowupTest extends TestCase
{
    use LazilyRefreshDatabase;
    protected function setUp(): void {parent::setUp();$this->travelTo(\Carbon\Carbon::parse('2026-11-13 12:00:00'));}
    private function actor(string $role='admin'): User {return User::factory()->create(['role'=>$role]);}
    private function concern(?Apprentice $a=null,array $fields=[]): SupportConcern
    {
        $c=SupportConcern::factory()->create(['apprentice_id'=>($a??Apprentice::factory()->create())->id,'initial_note'=>'PRIVATE CONCERN BODY']);$c->forceFill(['status'=>'Monitoring','acknowledged_at'=>now(),'action_due'=>today()->subDay(),'next_review'=>today()->addDay(),'next_action'=>'PRIVATE TARGET TEXT',...$fields])->save();return $c;
    }
    private function plan(Apprentice $a,string $status='Active',?string $due='2026-11-12'): SupportPlan
    {
        $p=SupportPlan::create(['apprentice_id'=>$a->id,'concern_ids'=>[],'draft'=>['fields'=>['review'=>'2030-01-01','support'=>'PRIVATE DRAFT SUPPORT']]]);$p->status=$status;
        if($status!=='Draft'){$p->active_plan=['approved_at'=>'2026-11-01T12:00:00+00:00','fields'=>['review'=>$due,'support'=>'PRIVATE ACTIVE SUPPORT'],'reason'=>'PRIVATE APPROVAL REASON'];}$p->save();return $p;
    }
    public function test_concern_counts_overlap_but_closed_concerns_never_enter_new_or_due_buckets(): void
    {
        $this->concern(null,['acknowledged_at'=>null,'next_review'=>null]);$this->concern(null,['action_due'=>today()]);$this->concern(null,['status'=>'Closed','acknowledged_at'=>null,'next_review'=>null]);$r=$this->actingAs($this->actor())->get('/support')->assertOk();$c=$r->viewData('concernCounts');$this->assertSame(2,$c['open']);$this->assertSame(1,$c['new']);$this->assertSame(1,$c['overdue']);$this->assertSame(1,$c['today']);$this->assertSame(1,$c['weekly']);$this->assertSame(1,$c['dates']);$this->assertSame(1,$c['closed']);$this->get('/support?state=new')->assertViewHas('concernRows',fn($rows)=>$rows->total()===1);
    }
    public function test_missing_action_date_is_a_check_not_overdue_or_due_today(): void
    {
        $this->concern(null,['action_due'=>null]);$r=$this->actingAs($this->actor())->get('/support?state=dates')->assertOk()->assertSee('Action or review date is missing');$this->assertSame(0,$r->viewData('concernCounts')['overdue']);$this->assertSame(0,$r->viewData('concernCounts')['today']);$this->assertSame(1,$r->viewData('concernRows')->total());
    }
    public function test_owner_checks_require_active_team_access_to_this_apprentice(): void
    {
        $owner=$this->actor('tutor');SupportCapability::create(['user_id'=>$owner->id,'duty'=>'member','scope'=>'assigned','apprentice_ids'=>[],'group_ids'=>[]]);$a=Apprentice::factory()->create(['tutor_id'=>$owner->id]);$this->concern($a,['owner_id'=>$owner->id]);$this->concern(null,['owner_id'=>$owner->id]);$this->concern(null,['owner_id'=>null]);$r=$this->actingAs($this->actor())->get('/support?state=owners')->assertOk();$this->assertSame(2,$r->viewData('concernRows')->total());$owner->active=false;$owner->save();$this->get('/support?state=owners')->assertViewHas('concernCounts',fn($c)=>$c['owners']===3);
    }
    public function test_assigned_inclusion_scope_cannot_expand_with_search_or_plan_filters(): void
    {
        $member=$this->actor('tutor');$a=Apprentice::factory()->create(['tutor_id'=>$member->id]);$outside=Apprentice::factory()->create();SupportCapability::create(['user_id'=>$member->id,'duty'=>'member','scope'=>'assigned','apprentice_ids'=>[],'group_ids'=>[]]);$this->concern($a);$this->plan($a);$this->concern($outside);$this->plan($outside);$this->actingAs($member)->get('/support')->assertOk()->assertSee($a->reference)->assertDontSee($outside->reference);$r=$this->get('/support?search='.$outside->reference.'&plan_state=overdue')->assertOk();$this->assertSame(0,$r->viewData('concernCounts')['open']);$this->assertSame(0,$r->viewData('planCounts')['all']);$a->tutor_id=null;$a->save();$this->get('/support')->assertViewHas('planCounts',fn($c)=>$c['all']===0);
    }
    public function test_private_case_plan_and_review_text_are_absent_from_summary_views(): void
    {
        $a=Apprentice::factory()->create();$this->concern($a);$p=$this->plan($a);$p->reviews=[['active_snapshot'=>$p->active_plan,'next'=>'2026-11-20','notes'=>'PRIVATE REVIEW NOTES']];$p->save();$r=$this->actingAs($this->actor())->get('/support')->assertOk();foreach(['PRIVATE CONCERN BODY','PRIVATE TARGET TEXT','PRIVATE DRAFT SUPPORT','PRIVATE ACTIVE SUPPORT','PRIVATE APPROVAL REASON','PRIVATE REVIEW NOTES'] as $text){$r->assertDontSee($text);$this->assertStringNotContainsString($text,json_encode($r->viewData('planRows')->items()));}$this->assertFalse($r->viewData('concernRows')->first()['concern']->hasAttribute('initial_note'));
    }
    public function test_concern_and_plan_pagination_are_independent_and_keep_filters(): void
    {
        $a=Apprentice::factory()->create();for($i=0;$i<23;$i++){$this->concern($a);$this->plan($a);}$r=$this->actingAs($this->actor())->get('/support?search='.$a->reference.'&state=overdue&plan_state=overdue&page=99&plan_page=1')->assertOk();$this->assertSame(2,$r->viewData('concernRows')->currentPage());$this->assertCount(3,$r->viewData('concernRows')->items());$this->assertSame(1,$r->viewData('planRows')->currentPage());$this->assertCount(20,$r->viewData('planRows')->items());$this->assertStringContainsString('plan_state=overdue',$r->viewData('concernRows')->previousPageUrl());
    }
    public function test_plan_states_keep_draft_closed_missing_and_known_dates_separate(): void
    {
        $a=Apprentice::factory()->create();$this->plan($a);$this->plan($a,'Active','2026-11-13');$this->plan($a,'Active','2026-11-14');$this->plan($a,'Active',null);$this->plan($a,'Draft');$this->plan($a,'Closed');$r=$this->actingAs($this->actor())->get('/support')->assertOk();$this->assertSame(['all'=>6,'due'=>2,'overdue'=>1,'today'=>1,'upcoming'=>1,'checks'=>1,'draft'=>1,'closed'=>1],$r->viewData('planCounts'));
    }
    public function test_latest_matching_approved_review_controls_due_date_and_draft_or_old_snapshot_cannot_reset_it(): void
    {
        $a=Apprentice::factory()->create();$p=$this->plan($a);$p->reviews=[['active_snapshot'=>$p->active_plan,'next'=>'2026-11-13'],['active_snapshot'=>['approved_at'=>'2025-01-01T00:00:00Z'],'next'=>'2030-01-01']];$p->save();$r=$this->actingAs($this->actor())->get('/support?plan_state=today')->assertOk();$this->assertSame(1,$r->viewData('planRows')->total());$this->assertSame('2026-11-13',$r->viewData('planRows')->first()['due']);$this->assertSame('2030-01-01',$p->fresh()->draft['fields']['review']);
    }
    public function test_invalid_plan_date_or_missing_approval_is_a_check_without_an_inferred_due_date(): void
    {
        $a=Apprentice::factory()->create();$this->plan($a,'Active','2026-02-30');$p=$this->plan($a);$p->active_plan=null;$p->save();$r=$this->actingAs($this->actor())->get('/support?plan_state=checks')->assertOk();$this->assertSame(2,$r->viewData('planRows')->total());$this->assertSame(0,$r->viewData('planCounts')['due']);
    }
    public function test_sources_are_linked_and_get_preserves_cases_plans_versions_and_audits(): void
    {
        $a=Apprentice::factory()->create();$c=$this->concern($a);$p=$this->plan($a);$before=[$c->fresh()->getAttributes(),$p->fresh()->getAttributes()];$r=$this->actingAs($this->actor())->get('/support')->assertOk()->assertSee(route('support.index',$a).'#concern-'.$c->id,false)->assertSee(route('support.plan',[$a,$p]),false);$this->assertSame($before,[$c->fresh()->getAttributes(),$p->fresh()->getAttributes()]);$this->assertDatabaseCount('learning_events',0);$this->assertDatabaseCount('marker_change_events',0);
    }
    public function test_unknown_concern_code_is_labelled_for_checking_without_breaking_worklist(): void
    {
        $this->concern(null,['code'=>'UNKNOWN']);$this->actingAs($this->actor())->get('/support')->assertOk()->assertSee('Code needs source check');
    }
    public function test_invalid_queries_and_roles_are_refused_instead_of_showing_broader_private_lists(): void
    {
        $this->actingAs($this->actor());foreach(['state=invalid','plan_state=invalid','search[]=x','page=0','plan_page=0'] as $q){$this->getJson('/support?'.$q)->assertUnprocessable();}foreach(['apprentice','employer','tutor','careers_adviser'] as $role){$this->actingAs($this->actor($role))->get('/support')->assertForbidden();}
    }
}
