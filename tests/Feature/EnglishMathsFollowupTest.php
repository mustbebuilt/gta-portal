<?php
namespace Tests\Feature;

use App\Models\{Apprentice,Qualification,TrainingTopic,User};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class EnglishMathsFollowupTest extends TestCase
{
    use LazilyRefreshDatabase;
    protected function setUp(): void { parent::setUp(); $this->travelTo(\Carbon\Carbon::parse('2026-11-13 12:00:00')); }
    private function actor(string $role='admin',?string $scope=null): User { return User::factory()->create(['role'=>$role,'subject_scope'=>$scope]); }
    private function qualification(Apprentice $a,array $data=[]): Qualification { return Qualification::factory()->create(['apprentice_id'=>$a->id,...$data]); }
    public function test_overlapping_counts_use_individual_targets_and_do_not_export_private_fields(): void
    {
        $a=Apprentice::factory()->create();$this->qualification($a,['start_date'=>'2025-11-12','notes'=>'PRIVATE QUALIFICATION']);$this->qualification($a,['start_date'=>'2025-11-13']);$this->qualification($a,['subject'=>'english','start_date'=>null]);
        $t=TrainingTopic::factory()->create(['apprentice_id'=>$a->id]);$t->evidence_stage='Awaiting review';$t->draft='PRIVATE TOPIC';$t->save();
        $r=$this->actingAs($this->actor())->get('/maths-english')->assertOk()->assertDontSee('PRIVATE QUALIFICATION')->assertDontSee('PRIVATE TOPIC');
        $c=$r->viewData('followupCounts');foreach(['all','overdue','today','checks','awaiting'] as $key){$this->assertSame(1,$c[$key]);}$this->assertSame(0,$c['unrecorded']);
        $r->assertSee(route('em.index',[$a,'subject'=>'maths','show'=>'today']));$this->assertDatabaseCount('learning_events',0);
    }
    public function test_specialist_both_scope_is_checked_for_each_apprentice_and_subject(): void
    {
        $u=$this->actor('functional_skills_tutor','both');$a=Apprentice::factory()->create(['maths_tutor_id'=>$u->id]);$this->qualification($a,['subject'=>'english','title'=>'HIDDEN ENGLISH','start_date'=>'2024-01-01']);$b=Apprentice::factory()->create();
        $this->actingAs($u)->get('/maths-english')->assertOk()->assertSee($a->reference)->assertDontSee($b->reference)->assertDontSee('English overdue qualifications')->assertViewHas('followupCounts',fn($c)=>$c['overdue']===0);
        $this->get('/maths-english?subject=english')->assertOk()->assertViewHas('followupCounts',fn($c)=>$c['all']===0);
        $this->get('/apprentices/'.$a->id.'/maths-english?subject=english')->assertForbidden();
    }
    public function test_main_tutor_and_apprentice_scopes_stay_separate_from_other_learners(): void
    {
        $u=$this->actor('tutor');$learner=$this->actor('apprentice');$a=Apprentice::factory()->create(['tutor_id'=>$u->id,'user_id'=>$learner->id]);$b=Apprentice::factory()->create();
        foreach([$u,$learner] as $actor){$this->actingAs($actor)->get('/maths-english')->assertOk()->assertSee($a->reference)->assertDontSee($b->reference);}
    }
    public function test_missing_qualifications_are_not_achieved_and_zero_hour_topics_are_excluded(): void
    {
        $a=Apprentice::factory()->create();$t=TrainingTopic::factory()->create(['apprentice_id'=>$a->id,'planned_hours'=>0]);$t->status='Secure';$t->save();
        $r=$this->actingAs($this->actor())->get('/maths-english?view=unrecorded&subject=maths')->assertOk()->assertSee('does not mean achieved or exempt');$s=$r->viewData('followupRows')->first()['subjects']['maths'];$this->assertSame(0,$s['planned_topics']);$this->assertSame(0,$s['completed_topics']);$this->assertSame(0,$r->viewData('followupCounts')['checks']);
    }
    public function test_pagination_search_and_filter_counts_remain_scoped_and_stable(): void
    {
        $u=$this->actor('tutor');Apprentice::factory()->count(22)->create(['tutor_id'=>$u->id]);$this->actingAs($u);$r=$this->get('/maths-english?view=unrecorded&subject=maths&page=99')->assertOk();$this->assertSame(2,$r->viewData('followupRows')->currentPage());$this->assertCount(2,$r->viewData('followupRows')->items());$this->assertStringContainsString('subject=maths',$r->viewData('followupRows')->previousPageUrl());$this->get('/maths-english?search=NO-MATCH-XYZ')->assertOk()->assertViewHas('followupCounts',fn($c)=>$c['all']===0);
    }
    public function test_qualification_and_evidence_filters_do_not_change_training_totals_or_sources(): void
    {
        $a=Apprentice::factory()->create();$q=$this->qualification($a,['start_date'=>'2025-11-13']);$t=TrainingTopic::factory()->create(['apprentice_id'=>$a->id,'topic'=>'Waiting topic']);$t->evidence_stage='Awaiting review';$t->save();$other=TrainingTopic::factory()->create(['apprentice_id'=>$a->id,'topic'=>'Completed topic']);$other->status='Completed';$other->save();$before=$t->fresh()->getAttributes();
        $r=$this->actingAs($this->actor())->get('/apprentices/'.$a->id.'/maths-english?subject=maths&show=today&topics=awaiting')->assertOk()->assertSee('1 of 2 assigned training topics completed.')->assertSee('Waiting topic')->assertDontSee('Completed topic');$this->assertCount(1,$r->viewData('qualifications'));$this->assertCount(1,$r->viewData('topicRows'));$this->assertSame($before,$t->fresh()->getAttributes());$this->assertDatabaseCount('learning_events',0);
    }
    public function test_achieved_and_exempt_qualifications_do_not_become_overdue_and_referred_links_work(): void
    {
        $a=Apprentice::factory()->create();$this->qualification($a,['status'=>'Achieved','start_date'=>'2024-01-01']);$this->qualification($a,['status'=>'Exemption confirmed','start_date'=>null]);$t=TrainingTopic::factory()->create(['apprentice_id'=>$a->id]);$t->evidence_stage='Referred';$t->save();$r=$this->actingAs($this->actor())->get('/maths-english?view=referred')->assertOk();$this->assertSame(0,$r->viewData('followupCounts')['overdue']);$this->assertSame(0,$r->viewData('followupCounts')['checks']);$r->assertSee(route('em.index',[$a,'subject'=>'maths','topics'=>'referred']));
    }
    public function test_invalid_queries_and_roles_are_rejected_and_invalid_subject_scope_grants_nothing(): void
    {
        $this->actingAs($this->actor());foreach(['view[]=all','subject=invalid','page=0','search[]=x'] as $query){$this->getJson('/maths-english?'.$query)->assertUnprocessable();}$a=Apprentice::factory()->create();$this->getJson('/apprentices/'.$a->id.'/maths-english?topics=invalid')->assertUnprocessable();$this->actingAs($this->actor('employer'))->get('/maths-english')->assertForbidden();$u=$this->actor('functional_skills_tutor','invalid');$a->maths_tutor_id=$u->id;$a->save();$this->actingAs($u)->get('/maths-english')->assertOk()->assertViewHas('followupCounts',fn($c)=>$c['all']===0);
    }
}
