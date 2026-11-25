<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\LearningBlockControl;
use App\Models\LearningTask;
use App\Models\ProgrammeOutcome;
use App\Models\User;
use App\Support\BlockRelease;
use App\Support\DashboardPriorities as P;
use App\Support\StaffAttention;
use App\Support\TaskAvailability as A;
use App\Support\TaskCatalogue;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class TaskAvailability58Test extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-13 12:00:00'));
    }

    private function actor(string $role = 'admin'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function task(Apprentice $a, array $fields = []): LearningTask
    {
        return LearningTask::factory()->create(['apprentice_id' => $a->id, 'reference' => 'work-'.uniqid(), 'title' => 'Fictional scoped task', ...$fields]);
    }

    private function f(string $kind = 'ready', ?string $date = 'past', string $status = 'Draft'): array
    {
        $u = $this->actor('apprentice');
        $t = $this->actor('tutor');
        $a = Apprentice::factory()->create(['user_id' => $u->id, 'tutor_id' => $t->id]);
        $template = TaskCatalogue::template('b1-t3');
        $block = 1;
        $reference = 'b1-t3';
        if ($kind === 'paper') {
            $item = collect(TaskCatalogue::tasks('autocare'))->first(fn ($row) => TaskCatalogue::template($row['id'])['kind'] === 'knowledge');
            $template = TaskCatalogue::template($item['id']);
            $reference = $item['id'];
            $block = (int) $item['block'];
        }
        if ($kind === 'source') {
            $template['kind'] = 'source-pending';
        }
        if ($kind === 'locked') {
            $item = collect(TaskCatalogue::tasks('autocare'))->first(fn ($row) => (int) $row['block'] === 2 && TaskCatalogue::template($row['id'])['kind'] !== 'knowledge');
            $template = TaskCatalogue::template($item['id']);
            $reference = $item['id'];
            $block = 2;
            LearningBlockControl::create(['apprentice_id' => $a->id, 'enabled' => true, 'exceptions' => [], 'history' => []]);
        }
        $due = match ($date) {
            'past' => today()->subDay(),'today' => today(),'future' => today()->addDay(),default => null
        };
        $task = $this->task($a, ['reference' => $reference, 'block' => $block, 'template' => $template, 'due_date' => $due, 'status' => $status]);

        return [$u, $a, $t, $task];
    }

    private function priorities($u, $a, $task): array
    {
        return P::for($u, collect([$a]), collect([$task]), collect());
    }

    #[TestWith(['ready', 'ready', true])] #[TestWith(['paper', 'paper', false])] #[TestWith(['source', 'source', false])] #[TestWith(['locked', 'locked', false])]
    public function test_control_state_is_presentation_only(string $kind, string $state, bool $available): void
    {
        [$u,$a,$t,$task] = $this->f($kind);
        $v = A::for($task);
        $this->assertSame($state, $v['state']);
        $this->assertSame($available, $v['available']);
    }

    #[TestWith(['paper'])] #[TestWith(['source'])] #[TestWith(['locked'])]
    public function test_unavailable_due_work_is_a_check_with_original_date_and_responsible_staff(string $kind): void
    {
        [$u,$a,$t,$task] = $this->f($kind);
        foreach ([$u, $t] as $actor) {
            $p = $this->priorities($actor, $a, $task);
            $this->assertSame(['all' => 1, 'overdue' => 0, 'today' => 0, 'checks' => 1], $p['counts']);
            $row = $p['rows'][0];
            $this->assertSame(today()->subDay()->format('d M Y'), $row['date']);
            $this->assertNotEmpty($row['reason']);
            $this->assertNotSame('Apprentice', $row['owner']);
        }$this->actingAs($u)->get(route('dashboard.index'))->assertOk()->assertSee('Needs checking')->assertSee(A::for($task)['label'])->assertSee(A::for($task)['detail']);
    }

    public function test_source_required_record_is_not_omitted_from_either_worklist(): void
    {
        [$u,$a,$t,$task] = $this->f('source', 'past', 'Source required');
        $this->assertSame(1, $this->priorities($u, $a, $task)['counts']['checks']);
        ProgrammeOutcome::factory()->create(['apprentice_id' => $a->id, 'context' => ['status' => 'Active', 'sourceRef' => 'Fictional checked source58']]);
        $rows = StaffAttention::for($t)['rows'];
        $this->assertTrue(collect($rows[0]['reasons'])->contains(fn ($x) => $x['kind'] === 'task' && $x['bucket'] === 'checks' && str_contains($x['text'], 'Task source not loaded')));
    }

    public function test_available_task_keeps_original_overdue_rule(): void
    {
        [$u,$a,$t,$task] = $this->f();
        $this->assertSame(1, $this->priorities($u, $a, $task)['counts']['overdue']);
        $this->assertNull($this->priorities($u, $a, $task)['rows'][0]['reason']);
    }

    public function test_blocked_detail_explains_read_only_access(): void
    {
        [$u,$a,$t,$task] = $this->f('locked');
        $this->actingAs($u)->get(route('tasks.show', $task))->assertOk()->assertSee('Training block not released')->assertSee('recorded deadline is not changed')->assertDontSee('Save draft keeps your work for later');
        $this->assertFalse(BlockRelease::state($task)['open']);
    }

    public function test_paper_detail_retains_original_assessment_guidance(): void
    {
        [$u,$a,$t,$task] = $this->f('paper');
        $this->actingAs($u)->get(route('tasks.show', $task))->assertOk()->assertSee('This is a paper assessment')->assertDontSee('Save draft keeps your work for later');
    }

    public function test_reads_do_not_change_deadlines_controls_audits_or_send_messages(): void
    {
        Mail::fake();
        Http::preventStrayRequests();
        [$u,$a,$t,$task] = $this->f('locked');
        $before = $task->fresh()->getAttributes();
        $control = LearningBlockControl::first()->getAttributes();
        $events = DB::table('learning_events')->count();
        $this->actingAs($u)->get(route('dashboard.index'))->assertOk();
        $this->get(route('tasks.show', $task))->assertOk();
        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->assertSame($control, LearningBlockControl::first()->getAttributes());
        $this->assertSame($events, DB::table('learning_events')->count());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_original_answer_save_control_still_refuses_unreleased_task(): void
    {
        [$u,$a,$t,$task] = $this->f('locked');
        $before = $task->fresh()->getAttributes();
        $this->actingAs($u)->post(route('tasks.save', $task), ['version' => $task->version, 'intent' => 'draft', 'answers' => []])->assertSessionHasErrors();
        $this->assertSame($before, $task->fresh()->getAttributes());
    }

    public function test_approved_and_submitted_work_does_not_become_editable_followup(): void
    {
        [$u,$a,$t,$task] = $this->f('ready', 'past', 'Approved');
        $this->assertFalse(A::for($task)['available']);
        $this->assertSame(0, $this->priorities($u, $a, $task)['counts']['all']);
        $task->status = 'Awaiting marking';
        $task->submitted_at = now()->subDays(8);
        $task->save();
        $this->assertSame(0, $this->priorities($u, $a, $task)['counts']['all']);
        $this->assertSame(1, $this->priorities($t, $a, $task)['counts']['overdue']);
    }

    public function test_future_deadline_does_not_enter_dashboard_priority_list(): void
    {
        [$u,$a,$t,$task] = $this->f('locked', 'future');
        $this->assertSame(0, $this->priorities($u, $a, $task)['counts']['all']);
    }

    public function test_existing_recorded_release_exception_is_respected(): void
    {
        [$u,$a,$t,$task] = $this->f('locked');
        $c = LearningBlockControl::first();
        $c->exceptions = ['2' => ['reason' => 'Fictional existing approved exception']];
        $c->save();
        $this->assertTrue(BlockRelease::state($task)['open']);
        $this->assertTrue(A::for($task)['available']);
        $this->assertSame(1, $this->priorities($u, $a, $task)['counts']['overdue']);
    }

    public function test_due_paper_assessment_is_a_staff_check(): void
    {
        [$u,$a,$t,$task] = $this->f('paper');
        ProgrammeOutcome::factory()->create(['apprentice_id' => $a->id, 'context' => ['status' => 'Active', 'sourceRef' => 'Fictional checked source58']]);
        $p = StaffAttention::for($t);
        $reason = collect($p['rows'][0]['reasons'])->firstWhere('kind', 'task');
        $this->assertSame('checks', $reason['bucket']);
        $this->assertStringContainsString('Paper assessment', $reason['text']);
    }

    public function test_original_role_scope_excludes_other_apprentice(): void
    {
        [$u,$a,$t,$task] = $this->f('locked');
        $other = $this->actor('apprentice');
        $this->assertSame(0, $this->priorities($other, $a, $task)['counts']['all']);
        $this->actingAs($other)->get(route('tasks.show', $task))->assertForbidden();
    }

    public function test_dashboard_and_attention_have_scoped_current_help(): void
    {
        [$u,$a,$t,$task] = $this->f();
        $this->actingAs($u)->get(route('dashboard.index'))->assertOk()->assertSee(route('help.index'))->assertDontSee('Help with this page:');
        $this->actingAs($t)->get(route('attention.index'))->assertOk()->assertSee(route('help.index'))->assertDontSee('Help with this page:');
    }
}
