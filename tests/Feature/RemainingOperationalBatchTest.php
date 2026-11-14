<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\AttendanceMark;
use App\Models\LearningTask;
use App\Models\Programme;
use App\Models\StaffDevelopmentProfile;
use App\Models\SupportConcern;
use App\Models\TrainingSession;
use App\Models\TutorGroup;
use App\Models\User;
use App\Support\BlockRelease;
use App\Support\ConcernDuration;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RemainingOperationalBatchTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role = 'admin'): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->save();

        return $u;
    }

    public function test_marker_history_records_differences_and_preserves_unchanged_source_dates(): void
    {
        $u = $this->actor();
        $a = Apprentice::factory()->create();
        $this->actingAs($u);
        $this->post('/apprentices/'.$a->id.'/markers', ['version' => 1, 'codes' => ['IM1', 'IM36'], 'confirmed' => 1, 'marker_reason' => 'Fictional source check'])->assertRedirect()->assertSessionHasNoErrors();
        $created = DB::table('apprentice_markers')->where('code', 'IM1')->value('created_at');
        $this->post('/apprentices/'.$a->id.'/markers', ['version' => 2, 'codes' => ['IM1'], 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('marker_change_events', 2);
        $event = DB::table('marker_change_events')->orderByDesc('id')->first();
        $this->assertSame(['IM1', 'IM36'], json_decode($event->before_codes, true));
        $this->assertSame(['IM1'], json_decode($event->after_codes, true));
        $this->assertSame($created, DB::table('apprentice_markers')->value('created_at'));
        $this->get('/apprentices/'.$a->id.'/support')->assertOk()->assertSee('Marker change history')->assertSee('Fictional source check');
        $this->post('/apprentices/'.$a->id.'/markers', ['version' => 3, 'codes' => ['IM1'], 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('marker_change_events', 2);
    }

    public function test_marker_audit_failure_rolls_back_markers_and_profile_version(): void
    {
        $a = Apprentice::factory()->create();
        $this->actingAs($this->actor());
        DB::listen(function ($q): void {
            if (str_contains($q->sql, 'marker_change_events') && str_starts_with(strtolower($q->sql), 'insert')) {
                throw new \RuntimeException('Fictional marker audit failure');
            }
        });
        $this->post('/apprentices/'.$a->id.'/markers', ['version' => 1, 'codes' => ['IM36'], 'confirmed' => 1])->assertStatus(500);
        $this->assertDatabaseCount('apprentice_markers', 0);
        $this->assertSame(1, $a->fresh()->version);
    }

    public function test_marker_history_is_not_exposed_in_a_tutor_concern_receipt(): void
    {
        $t = $this->actor('tutor');
        $a = Apprentice::factory()->create(['tutor_id' => $t->id]);
        $this->actingAs($this->actor())->post('/apprentices/'.$a->id.'/markers', ['version' => 1, 'codes' => ['IM1'], 'confirmed' => 1, 'marker_reason' => 'Private source context'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($t)->get('/apprentices/'.$a->id.'/support')->assertOk()->assertDontSee('Private source context')->assertDontSee('Marker change history');
    }

    public function test_concern_duration_unions_overlap_inclusive_calendar_days_and_preserves_date_gaps(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 12)->setTime(18, 0));
        $a = Apprentice::factory()->create();
        $make = function ($from, $to = null, $state = 'Closed') use ($a) {
            $c = SupportConcern::factory()->create(['apprentice_id' => $a->id]);
            $c->acknowledged_at = $from;
            $c->closed_at = $to;
            $c->status = $state;
            $c->save();

            return $c;
        };
        $r = ConcernDuration::summarise(collect([$make('2026-11-01', '2026-11-05'), $make('2026-11-04', '2026-11-07'), $make('2026-11-10', null, 'Monitoring'), $make(null, null, 'New'), $make('2026-11-08', null)]));
        $this->assertSame(10, $r['days']);
        $this->assertSame(3, $r['recorded_intervals']);
        $this->assertSame(1, $r['unacknowledged']);
        $this->assertSame(1, $r['date_checks']);
        $this->assertSame(1, $r['open']);
        $this->assertNull(ConcernDuration::summarise(collect())['days']);
        $this->actingAs($this->actor())->get('/reports/retention')->assertOk()->assertSee('10')->assertSee('recorded acknowledged concern days');
        $this->actingAs($this->actor('tutor'))->get('/reports/retention')->assertForbidden();
    }

    public function test_staff_overview_keeps_employee_private_counts_hidden_and_manager_scope_limited(): void
    {
        $u = $this->actor('tutor');
        $m = $this->actor('manager');
        $outsider = $this->actor('staff');
        $p = new StaffDevelopmentProfile(['user_id' => $u->id, 'payload' => ['entries' => [['kind' => 'note', 'shared' => false, 'title' => 'Private management item'], ['kind' => 'cpd', 'shared' => false]], 'actions' => [['status' => 'In progress', 'due' => today()->subDay()->toDateString()]], 'schedules' => [['status' => 'Planned', 'due' => today()->toDateString()]]]]);
        $p->manager_id = $m->id;
        $p->save();
        $this->actingAs($u)->get('/staff-development')->assertOk()->assertSee('1 visible development entries')->assertDontSee('Private management item')->assertDontSee($outsider->name);
        $this->actingAs($m)->get('/staff-development')->assertOk()->assertSee('2 visible development entries')->assertSee('1 overdue actions')->assertDontSee($outsider->name);
        $this->actingAs($this->actor())->get('/staff-development')->assertOk()->assertDontSee($u->name);
    }

    private function sessionData(TutorGroup $g): array
    {
        return ['tutor_group_id' => $g->id, 'name' => 'Fictional repeated learning', 'session_date' => '2026-12-01', 'start_time' => '09:00', 'end_time' => '16:00', 'planned_learning' => 'Fictional planned learning', 'breaks_checked' => 1, 'repeat_weeks' => 3];
    }

    public function test_repeated_sessions_save_three_distinct_rosters_without_delivery_or_hours(): void
    {
        $g = TutorGroup::factory()->create();
        $a = Apprentice::factory()->create(['tutor_group_id' => $g->id]);
        $this->actingAs($this->actor())->post('/sessions', $this->sessionData($g))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('training_sessions', 3);
        $this->assertDatabaseCount('attendance_marks', 0);
        $this->assertSame(['2026-12-01', '2026-12-08', '2026-12-15'], TrainingSession::orderBy('session_date')->get()->map(fn ($s) => $s->session_date->toDateString())->all());
        foreach (TrainingSession::all() as $s) {
            $this->assertSame([$a->id], $s->members);
            $this->assertNull($s->delivered_learning);
        }
        $a->tutor_group_id = null;
        $a->save();
        $this->assertSame([$a->id], TrainingSession::first()->members);
    }

    public function test_repeated_planning_collision_rolls_back_entire_batch_and_forbidden_roles_are_denied(): void
    {
        $g = TutorGroup::factory()->create();
        $a = Apprentice::factory()->create(['tutor_group_id' => $g->id]);
        TrainingSession::factory()->create(['tutor_group_id' => $g->id, 'session_date' => '2026-12-08', 'members' => [$a->id], 'start_time' => '09:00', 'end_time' => '16:00']);
        $this->actingAs($this->actor())->post('/sessions', $this->sessionData($g))->assertSessionHasErrors('session_date');
        $this->assertDatabaseCount('training_sessions', 1);
        $this->actingAs($this->actor('apprentice'))->post('/sessions', $this->sessionData($g))->assertForbidden();
    }

    public function test_register_correction_requires_reason_keeps_before_snapshot_and_replaces_hours(): void
    {
        $g = TutorGroup::factory()->create();
        $a = Apprentice::factory()->create();
        $s = TrainingSession::factory()->create(['tutor_group_id' => $g->id, 'members' => [$a->id]]);
        $this->actingAs($this->actor());
        $data = ['version' => 1, 'delivered_learning' => 'Fictional actual learning', 'marks' => [$a->id => ['code' => 'O', 'arrival' => '09:00', 'departure' => '16:00', 'otj_confirmed' => 1]]];
        $this->post('/sessions/'.$s->id, $data)->assertRedirect()->assertSessionHasNoErrors();
        $before = AttendanceMark::first()->otj_minutes;
        $this->post('/sessions/'.$s->id, [...$data, 'version' => 2])->assertSessionHasErrors('correction_reason');
        $this->assertSame(2, $s->fresh()->version);
        $data['marks'][$a->id]['departure'] = '15:00';
        $this->post('/sessions/'.$s->id, [...$data, 'version' => 2, 'correction_reason' => 'Fictional departure correction'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($before - 60, AttendanceMark::first()->otj_minutes);
        $h = $s->fresh()->history;
        $this->assertSame($before, $h[1]['before_marks'][0]['otj_minutes']);
        $this->assertSame('Fictional departure correction', $h[1]['correction_reason']);
        $this->get('/sessions/'.$s->id)->assertOk()->assertSee('Fictional departure correction');
    }

    public function test_unsupported_programme_cannot_receive_autocare_source_tasks(): void
    {
        $p = Programme::factory()->create(['standard_code' => 'ST0070', 'version' => '1.0']);
        $a = Apprentice::factory()->create(['programme_id' => $p->id]);
        $this->actingAs($this->actor())->post('/apprentices/'.$a->id.'/learning', ['confirmed' => 1])->assertSessionHasErrors('confirmed');
        $this->assertDatabaseCount('learning_tasks', 0);
        $this->get('/apprentices/'.$a->id.'/learning')->assertOk()->assertDontSee('Assign Autocare tasks');
    }

    private function allocation(): array
    {
        $l = $this->actor('apprentice');
        $t = $this->actor('tutor');
        $a = Apprentice::factory()->create(['user_id' => $l->id, 'tutor_id' => $t->id]);
        $admin = $this->actor();
        $this->actingAs($admin)->post('/apprentices/'.$a->id.'/learning', ['confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();

        return [$a, $l, $t, $admin];
    }

    public function test_new_allocations_lock_later_blocks_but_allow_reading_original_sources(): void
    {
        [$a,$l] = $this->allocation();
        $task = LearningTask::where('apprentice_id', $a->id)->where('reference', 'b2-t3')->firstOrFail();
        $this->assertFalse(BlockRelease::state($task)['open']);
        $this->actingAs($l)->get('/tasks/'.$task->id)->assertOk()->assertSee('Complete all five source tasks in Block 1 first');
        $this->post('/tasks/'.$task->id, ['version' => 1, 'intent' => 'draft'])->assertSessionHasErrors('block');
        $this->assertSame(1, $task->fresh()->version);
    }

    public function test_all_five_source_approvals_release_next_block_without_ksb_or_programme_signoff(): void
    {
        [$a] = $this->allocation();
        $tasks = LearningTask::where('apprentice_id', $a->id)->where('block', 1)->get();
        foreach ($tasks as $t) {
            $t->status = 'Approved';
            $t->save();
        }$next = LearningTask::where('apprentice_id', $a->id)->where('reference', 'b2-t3')->firstOrFail();
        $this->assertTrue(BlockRelease::state($next)['open']);
        $tasks->last()->status = 'Referred';
        $tasks->last()->save();
        $this->assertFalse(BlockRelease::state($next)['open']);
    }

    public function test_administrator_exception_withdrawal_and_stale_guards_do_not_approve_tasks(): void
    {
        [$a,$l,$t,$admin] = $this->allocation();
        $task = LearningTask::where('apprentice_id', $a->id)->where('reference', 'b2-t3')->firstOrFail();
        $payload = ['version' => 1, 'enabled' => 1, 'operation' => 'release', 'block' => 2, 'reason' => 'Fictional individual sequence exception'];
        $this->actingAs($t)->post('/apprentices/'.$a->id.'/block-controls', $payload)->assertForbidden();
        $this->actingAs($admin)->post('/apprentices/'.$a->id.'/block-controls', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(BlockRelease::state($task)['open']);
        $this->assertSame('Draft', $task->fresh()->status);
        $this->post('/apprentices/'.$a->id.'/block-controls', $payload)->assertSessionHasErrors('version');
        $this->post('/apprentices/'.$a->id.'/block-controls', [...$payload, 'version' => 2, 'operation' => 'withdraw'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse(BlockRelease::state($task)['open']);
    }

    public function test_legacy_tasks_are_not_silently_locked_and_existing_controls_do_not_break_allocation(): void
    {
        $a = Apprentice::factory()->create();
        $task = LearningTask::factory()->create(['apprentice_id' => $a->id, 'block' => 2]);
        $this->assertTrue(BlockRelease::state($task)['open']);
        $b = Apprentice::factory()->create();
        $this->actingAs($this->actor())->post('/apprentices/'.$b->id.'/block-controls', ['version' => 0, 'enabled' => 1, 'operation' => 'settings', 'reason' => 'Fictional enable sequence'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/apprentices/'.$b->id.'/learning',['confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('learning_tasks',51);
    }
}
