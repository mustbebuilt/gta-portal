<?php
namespace Tests\Feature;

use App\Models\{Apprentice,LearningTask,SmartAction,TrainingSession,TutorGroup,User};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DashboardPrioritiesTest extends TestCase
{
    use LazilyRefreshDatabase;
    protected function setUp(): void {parent::setUp();$this->travelTo(\Carbon\Carbon::parse('2026-11-13 12:00:00'));}
    private function actor(string $role='admin'): User {return User::factory()->create(['role'=>$role]);}
    private function task(Apprentice $a,array $fields=[]): LearningTask
    {
        return LearningTask::factory()->create(['apprentice_id'=>$a->id,'reference'=>'work-'.uniqid(),'title'=>'Fictional scoped task',...$fields]);
    }
    public function test_tutor_list_is_read_only_and_only_contains_assigned_records_and_authorised_source_links(): void
    {
        $u=$this->actor('tutor');$own=Apprentice::factory()->create(['tutor_id'=>$u->id]);$other=Apprentice::factory()->create();$t=$this->task($own,['due_date'=>today()->subDay()]);$foreign=$this->task($other,['due_date'=>today()->subDay(),'title'=>'Other tutor private task']);$before=$t->fresh()->getAttributes();
        $r=$this->actingAs($u)->get(route('dashboard.index'))->assertOk()->assertSee('Work today')->assertDontSee('Other tutor private task');$list=$r->viewData('priorityList');$this->assertSame(2,$list->total());$this->assertSame(route('tasks.show',$t),$list->items()[0]['url']);$this->get($list->items()[0]['url'])->assertOk();$this->get(route('tasks.show',$foreign))->assertForbidden();$this->assertSame($before,$t->fresh()->getAttributes());$this->assertDatabaseCount('marking_reminders',0);
    }
    public function test_marking_boundary_and_missing_future_submission_dates_follow_existing_rule(): void
    {
        $a=Apprentice::factory()->create();$at=$this->task($a,['status'=>'Awaiting marking','submitted_at'=>now()->subDays(7)]);$late=$this->task($a,['status'=>'Awaiting marking','submitted_at'=>now()->subDays(7)->subSecond()]);$missing=$this->task($a,['status'=>'Awaiting marking']);$future=$this->task($a,['status'=>'Awaiting marking','submitted_at'=>now()->addDay()]);
        $r=$this->actingAs($this->actor())->get(route('dashboard.index'))->assertOk();$p=$r->viewData('priorities');$this->assertSame(['all'=>5,'overdue'=>1,'today'=>1,'checks'=>3],$p['counts']);$keys=$p['rows']->pluck('key')->all();$this->assertContains('task-'.$at->id,$keys);$this->assertContains('task-'.$late->id,$keys);$this->assertContains('task-'.$missing->id,$keys);$this->assertContains('task-'.$future->id,$keys);
    }
    public function test_linked_actions_do_not_duplicate_tasks_and_completed_future_work_is_excluded(): void
    {
        $a=Apprentice::factory()->create();$t=$this->task($a,['due_date'=>today()->subDays(2)]);SmartAction::factory()->create(['apprentice_id'=>$a->id,'learning_task_id'=>$t->id]);$done=$this->task($a,['status'=>'Approved','due_date'=>today()->subDays(4)]);$future=$this->task($a,['due_date'=>today()->addDay()]);$action=SmartAction::factory()->create(['apprentice_id'=>$a->id,'due_date'=>today(),'title'=>'Standalone due today']);
        $r=$this->actingAs($this->actor())->get(route('dashboard.index'))->assertOk();$p=$r->viewData('priorities');$this->assertSame(['all'=>3,'overdue'=>1,'today'=>1,'checks'=>1],$p['counts']);$this->assertSame(['task-'.$t->id,'action-'.$action->id],$p['rows']->whereIn('kind',['task','action'])->pluck('key')->all());$this->get($p['rows'][1]['url'])->assertOk();
    }
    public function test_apprentice_sees_own_editable_work_and_own_to_do_actions_without_marking_or_other_owners(): void
    {
        $u=$this->actor('apprentice');$a=Apprentice::factory()->create(['user_id'=>$u->id]);$other=Apprentice::factory()->create();$t=$this->task($a,['due_date'=>today()]);$this->task($a,['status'=>'Awaiting marking','submitted_at'=>now()->subDays(8)]);$this->task($other,['due_date'=>today(),'title'=>'Hidden other learner work']);$action=SmartAction::factory()->create(['apprentice_id'=>$a->id,'due_date'=>today()->subDay(),'owner'=>'Apprentice']);SmartAction::factory()->create(['apprentice_id'=>$a->id,'due_date'=>today()->subDay(),'owner'=>'Tutor']);$waiting=SmartAction::factory()->create(['apprentice_id'=>$a->id,'due_date'=>today()->subDay(),'owner'=>'Apprentice']);$waiting->status='With tutor';$waiting->save();
        $r=$this->actingAs($u)->get(route('dashboard.index'))->assertOk()->assertSee('Your next training steps')->assertDontSee('Hidden other learner work');$p=$r->viewData('priorities');$this->assertSame(2,$p['counts']['all']);$this->assertEqualsCanonicalizing(['task-'.$t->id,'action-'.$action->id],$p['rows']->pluck('key')->all());
    }
    public function test_scope_filter_and_pagination_preserve_counts_and_use_current_group_only(): void
    {
        $group=TutorGroup::factory()->create();$a=Apprentice::factory()->create(['tutor_group_id'=>$group->id]);$other=Apprentice::factory()->create();for($i=0;$i<18;$i++){$this->task($a,['due_date'=>today()->subDays(1+$i)]);}$this->task($other,['due_date'=>today()->subDay()]);
        $r=$this->actingAs($this->actor())->get(route('dashboard.index',['group'=>$group->id,'priority'=>'overdue','priority_page'=>2]))->assertOk();$list=$r->viewData('priorityList');$this->assertSame(18,$list->total());$this->assertCount(3,$list->items());$this->assertStringContainsString('group='.$group->id,$list->previousPageUrl());$this->assertStringContainsString('priority=overdue',$list->previousPageUrl());$this->assertSame(19,$r->viewData('priorities')['counts']['all']);
    }
    public function test_missing_dates_are_checks_empty_filter_is_not_claimed_as_completion_and_invalid_filters_refuse(): void
    {
        $a=Apprentice::factory()->create();$this->task($a);$this->actingAs($this->actor());$r=$this->get(route('dashboard.index',['priority'=>'overdue']))->assertOk()->assertSee('No matching work in this view. Other areas may still need attention.')->assertDontSee('All work complete');$this->assertSame(2,$r->viewData('priorities')['counts']['checks']);$this->assertSame(0,$r->viewData('priorityList')->total());$this->get(route('dashboard.index',['priority'=>'invented']))->assertStatus(422);$this->get(route('dashboard.index',['priority_page'=>'2bad']))->assertStatus(422);$this->get(route('dashboard.index',['priority_page'=>0]))->assertStatus(422);
    }
    public function test_work_status_uses_real_deadline_order_and_todays_ended_register_is_due_today(): void
    {
        $tutor = $this->actor('tutor');
        $group = TutorGroup::factory()->create(['tutor_id'=>$tutor->id]);
        $person = Apprentice::factory()->create(['tutor_id'=>$tutor->id, 'tutor_group_id'=>$group->id]);
        $old = $this->task($person, ['due_date'=>'2026-10-26']);
        $new = $this->task($person, ['due_date'=>'2026-11-02']);
        $register = TrainingSession::factory()->create([
            'tutor_group_id'=>$group->id, 'members'=>[$person->id], 'session_date'=>today()->toDateString(),
            'start_time'=>'09:00', 'end_time'=>'11:00', 'breaks'=>[],
        ]);
        $page = $this->actingAs($tutor)->get(route('dashboard.index', [
            'group'=>$group->id, 'work_type'=>'task', 'priority'=>'overdue',
        ]))->assertOk();
        $this->assertSame(['all'=>2,'overdue'=>2,'today'=>0,'checks'=>0], $page->viewData('workCounts'));
        $this->assertSame(['task-'.$old->id,'task-'.$new->id], $page->viewData('priorityList')->getCollection()->pluck('key')->all());
        $this->assertSame('today', $page->viewData('priorities')['rows']->firstWhere('key', 'register:'.$register->id)['bucket']);
        $scope = ['group'=>$group->id, 'priority_page'=>1, 'priority'=>'overdue', 'work_type'=>'task'];
        $url = \App\Support\Workday83::openItem($page->viewData('priorityList')->items()[0], $page->viewData('workScope'));
        $page->assertSee($url);
        $this->get($url)->assertOk()->assertSee('Back to Work today')
            ->assertSee(route('dashboard.index', $scope).'#training-priorities');
        $this->get(route('dashboard.index', ['work_type'=>['task']]))->assertStatus(422);
    }

    public function test_register_next_keeps_the_group_and_returns_to_work_today_when_finished(): void
    {
        $tutor = $this->actor('tutor');
        $group = TutorGroup::factory()->create(['tutor_id'=>$tutor->id]);
        $person = Apprentice::factory()->create(['tutor_id'=>$tutor->id,'tutor_group_id'=>$group->id]);
        $first = TrainingSession::factory()->create(['tutor_group_id'=>$group->id,'members'=>[$person->id],'session_date'=>'2026-11-10']);
        $second = TrainingSession::factory()->create(['tutor_group_id'=>$group->id,'members'=>[$person->id],'session_date'=>'2026-11-11']);
        $context = ['work_today'=>['from'=>'1','group'=>$group->id,'work_type'=>'register']];
        $this->actingAs($tutor)->get(route('attendance.show', ['session'=>$first,'group'=>$group->id,...$context]))
            ->assertOk()->assertSee('Back to Work today');
        $payload = [
            'version'=>$first->version, 'after_save'=>'next', 'next_scope'=>['group'=>$group->id],
            'marks'=>[$person->id=>['code'=>'O','arrival'=>'09:00','departure'=>'16:00']],
        ];
        $this->post(route('attendance.save', ['session'=>$first,...$context]), $payload)->assertSessionHasNoErrors()
            ->assertRedirect(route('attendance.show', ['session'=>$second,'group'=>$group->id,...$context]));
        $this->post(route('attendance.save', ['session'=>$second,...$context]), [...$payload,'version'=>$second->version])
            ->assertSessionHasNoErrors()->assertRedirect(route('dashboard.index', ['group'=>$group->id,'work_type'=>'register']).'#training-priorities');
    }

    public function test_action_anchor_and_review_draft_keep_the_work_today_scope(): void
    {
        $tutor = $this->actor('tutor');
        $person = Apprentice::factory()->create(['tutor_id'=>$tutor->id]);
        $action = SmartAction::factory()->create(['apprentice_id'=>$person->id, 'due_date'=>today()]);
        $page = $this->actingAs($tutor)->get(route('dashboard.index',['apprentice'=>$person->id,'work_type'=>'action']))
            ->assertOk();
        $url = \App\Support\Workday83::openItem($page->viewData('priorityList')->items()[0], $page->viewData('workScope'));
        $this->assertStringEndsWith('#action-'.$action->id, $url);
        $this->get($url)->assertOk()->assertSee('Back to Work today');
        $context = ['work_today'=>['from'=>'1','apprentice'=>$person->id,'work_type'=>'review']];
        $this->get(route('reviews.create', [$person, ...$context]))->assertOk()->assertSee('Back to Work today');
        $response = $this->post(route('reviews.store', [$person, ...$context]), [
            'fields'=>['format83'=>'simple83','summary'=>'Fictional draft from work list'],
        ])->assertSessionHasNoErrors();
        $draft = \App\Models\ProgressReviewDraft::sole();
        $response->assertRedirect(route('reviews.edit', [$person, $draft, ...$context]));
        $this->get(route('reviews.edit', [$person, $draft, ...$context]))->assertOk()
            ->assertSee(route('dashboard.index', ['apprentice'=>$person->id,'work_type'=>'review']).'#training-priorities');
    }

    public function test_unsupported_roles_cannot_open_dashboard_and_existing_report_does_not_include_live_list(): void
    {
        $this->actingAs($this->actor('employer'))->get(route('dashboard.index'))->assertForbidden();$this->actingAs($this->actor())->get(route('dashboard.report'))->assertOk()->assertDontSee('data-priority-row',false);
    }
}
