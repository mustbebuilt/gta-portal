<?php
namespace Tests\Feature;

use App\Models\{Apprentice,CareerDiscussion,User};
use App\Support\CareersFollowup;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CareersFollowupTest extends TestCase
{
    use LazilyRefreshDatabase;
    protected function setUp(): void {parent::setUp();$this->travelTo(\Carbon\Carbon::parse('2026-11-13 12:00:00'));}
    private function actor(string $role='admin'): User {return User::factory()->create(['role'=>$role]);}
    private function source(array $actions=[],string $type='progression',?User $adviser=null,?Apprentice $person=null): array
    {
        $adviser??=$this->actor('careers_adviser');$person??=Apprentice::factory()->create(['careers_adviser_id'=>$adviser->id]);$d=CareerDiscussion::factory()->create(['apprentice_id'=>$person->id,'adviser_id'=>$adviser->id,'type'=>$type,'goals'=>'PRIVATE GOALS EXCLUDED FROM WORKLIST','advice'=>'PRIVATE ADVICE EXCLUDED FROM WORKLIST']);$d->actions=$actions;$d->save();return [$person,$d,$adviser];
    }
    private function action(string $id,array $data=[]): array {return ['id'=>$id,'title'=>'Fictional action '.$id,'status'=>'To do','owner'=>'Apprentice','due_date'=>'2026-11-12','criteria'=>'PRIVATE CRITERIA','support'=>'PRIVATE SUPPORT','agreed_at'=>'2026-11-01','created_by'=>1,...$data];}
    public function test_counts_are_actions_overlap_and_respect_search_type_and_completed_status(): void
    {
        [$a]=$this->source([$this->action('late',['status'=>'Ready for adviser check']),$this->action('today',['due_date'=>'2026-11-13']),$this->action('complete',['status'=>'Completed'])]);$this->source([$this->action('other')],'employment');
        $r=$this->actingAs($this->actor())->get('/careers?search='.$a->reference.'&type=progression&show=overdue')->assertOk();$c=$r->viewData('workCounts');$this->assertSame(2,$c['all']);$this->assertSame(1,$c['checking']);$this->assertSame(1,$c['overdue']);$this->assertSame(1,$c['today']);$this->assertSame(1,$r->viewData('items')->total());$r->assertDontSee('Fictional action complete')->assertDontSee('PRIVATE GOALS')->assertDontSee('PRIVATE ADVICE')->assertDontSee('PRIVATE CRITERIA')->assertDontSee('PRIVATE SUPPORT');
    }
    public function test_missing_and_impossible_dates_are_checks_not_overdue(): void
    {
        $this->source([$this->action('blank',['due_date'=>'']),$this->action('missing',['due_date'=>null]),$this->action('invalid',['due_date'=>'2026-02-30']),$this->action('future',['due_date'=>'2027-01-01'])]);$r=$this->actingAs($this->actor())->get('/careers?show=dates')->assertOk();$this->assertSame(3,$r->viewData('items')->total());$this->assertSame(0,$r->viewData('workCounts')['overdue']);$r->assertSee('no overdue date inferred');$this->assertNull(CareersFollowup::date('2026-02-29'));$this->assertSame('2024-02-29',CareersFollowup::date('2024-02-29'));
    }
    public function test_assignment_checks_use_current_active_adviser_and_preserve_recorded_attribution(): void
    {
        [$a,$d,$old]=$this->source([$this->action('check',['status'=>'Ready for adviser check'])]);$replacement=$this->actor('careers_adviser');$replacement->active=false;$replacement->save();$a->careers_adviser_id=$replacement->id;$a->save();$r=$this->actingAs($this->actor())->get('/careers?show=assignments')->assertOk()->assertSee('Adviser assignment needs checking');$this->assertSame(1,$r->viewData('items')->total());$this->assertSame($old->id,$d->fresh()->adviser_id);$this->get('/apprentices/'.$a->id.'/careers?discussion='.$d->id)->assertOk()->assertSee('Recorded adviser: '.$old->name);$this->actingAs($old)->get('/careers')->assertOk()->assertDontSee('Fictional action check');$this->get('/apprentices/'.$a->id.'/careers?discussion='.$d->id)->assertForbidden();
    }
    public function test_action_and_apprentice_pagination_clamp_independently_and_keep_filters(): void
    {
        $actions=[];for($i=0;$i<23;$i++){$actions[]=$this->action('a-'.$i);}[$a]=$this->source($actions);$r=$this->actingAs($this->actor())->get('/careers?search='.$a->reference.'&type=progression&show=overdue&action_page=99&apprentice_page=99')->assertOk();$this->assertSame(2,$r->viewData('items')->currentPage());$this->assertCount(3,$r->viewData('items')->items());$this->assertSame(1,$r->viewData('apprentices')->currentPage());$this->assertStringContainsString('search='.$a->reference,$r->viewData('items')->previousPageUrl());
    }
    public function test_older_source_links_and_edit_requests_open_exact_discussion_beyond_first_page(): void
    {
        [$a,$old,$u]=$this->source([$this->action('old')]);for($i=0;$i<12;$i++){CareerDiscussion::factory()->create(['apprentice_id'=>$a->id,'adviser_id'=>$u->id,'discussion_date'=>'2026-11-12']);}$this->actingAs($u)->get('/careers')->assertOk()->assertSee('discussion='.$old->id,false);$this->get('/apprentices/'.$a->id.'/careers?discussion='.$old->id)->assertOk()->assertSee('id="discussion-'.$old->id.'"',false)->assertViewHas('discussions',fn($c)=>$c->total()===1);$this->get('/apprentices/'.$a->id.'/careers?edit='.$old->id)->assertOk()->assertSee('Amend careers discussion')->assertViewHas('discussions',fn($c)=>$c->first()->id===$old->id);
    }
    public function test_history_filter_and_ties_paginate_without_losing_source_records(): void
    {
        [$a,$d,$u]=$this->source();for($i=0;$i<11;$i++){CareerDiscussion::factory()->create(['apprentice_id'=>$a->id,'adviser_id'=>$u->id,'type'=>'employment']);}$before=$d->fresh()->getAttributes();$r=$this->actingAs($u)->get('/apprentices/'.$a->id.'/careers?history_type=employment&history_page=99')->assertOk();$this->assertSame(2,$r->viewData('discussions')->currentPage());$this->assertSame(11,$r->viewData('discussions')->total());$this->assertSame($before,$d->fresh()->getAttributes());$this->assertDatabaseCount('learning_events',0);$this->assertDatabaseCount('careers_prompts',0);
    }
    public function test_corrupt_action_shape_is_visible_as_source_check_and_source_fails_safely(): void
    {
        [$a,$d]=$this->source(['bad']);$this->actingAs($this->actor())->get('/careers')->assertOk()->assertSee('Unreadable action sources (1)')->assertViewHas('workCounts',fn($c)=>$c['all']===0);$this->getJson('/apprentices/'.$a->id.'/careers?discussion='.$d->id)->assertUnprocessable()->assertJsonPath('message','Careers action source needs administrator reconciliation before this discussion can be opened. No record has been changed.');$this->get('/apprentices/'.$a->id.'/careers?discussion='.$d->id)->assertUnprocessable()->assertSee('Careers action source needs administrator reconciliation')->assertSee('Back to scoped Careers work');$this->assertSame(['bad'],$d->fresh()->actions);
    }
    public function test_apprentice_and_staff_privacy_and_foreign_discussion_ids_cannot_expand_scope(): void
    {
        [$a,$d,$u]=$this->source([$this->action('private')]);[$foreign,$other]=$this->source();$learner=$this->actor('apprentice');$a->user_id=$learner->id;$a->save();$this->actingAs($learner)->get('/careers')->assertOk()->assertDontSee('Fictional action private')->assertDontSee('career-work-show');$this->get('/apprentices/'.$a->id.'/careers?discussion='.$other->id)->assertNotFound();$this->get('/apprentices/'.$a->id.'/careers?edit='.$d->id)->assertForbidden();foreach(['tutor','employer','functional_skills_tutor'] as $role){$this->actingAs($this->actor($role))->get('/careers')->assertForbidden();$this->get('/apprentices/'.$a->id.'/careers')->assertForbidden();}
    }
    public function test_invalid_queries_fail_validation_instead_of_silently_broadening_scope(): void
    {
        [$a,$d]=$this->source();$this->actingAs($this->actor());foreach(['show=invalid','search[]=x','type[]=x','action_page=0','apprentice_page=0'] as $query){$this->getJson('/careers?'.$query)->assertUnprocessable();}foreach(['history_type=invalid','history_page=0','discussion[]=1','edit[]=1'] as $query){$this->getJson('/apprentices/'.$a->id.'/careers?'.$query)->assertUnprocessable();}$this->getJson('/apprentices/'.$a->id.'/careers?discussion='.$d->id.'&edit=999')->assertUnprocessable();
    }
    public function test_prompt_control_explicitly_describes_global_scope_and_get_never_records_prompts(): void
    {
        $this->actingAs($this->actor())->get('/careers?search=NO-SUCH-APPRENTICE')->assertOk()->assertSee('all eligible sources regardless of the search and filters above')->assertSee('Record all due calendar prompts');$this->assertDatabaseCount('careers_prompts',0);$this->assertDatabaseCount('careers_prompt_events',0);
    }
}
