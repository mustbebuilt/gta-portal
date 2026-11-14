<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\Employer;
use App\Models\LearningBlockControl;
use App\Models\LearningTask;
use App\Models\Programme;
use App\Models\ProgressReviewDraft;
use App\Models\SmartAction;
use App\Models\StaffDevelopmentProfile;
use App\Models\SupportConcern;
use App\Models\User;
use App\Support\AssignmentHistory;
use App\Support\AttendanceLedger;
use App\Support\BlockRelease;
use App\Support\TaskCatalogue;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConnectedBatchCompletionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role = 'admin'): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->save();

        return $u;
    }

    public function test_assignment_changes_save_minimal_link_history_and_counts_do_not_claim_lifetime_coverage(): void
    {
        $old = $this->actor('tutor');
        $new = $this->actor('tutor');
        $e1 = Employer::factory()->create();
        $e2 = Employer::factory()->create();
        $this->actingAs($this->actor());
        $data = ['reference' => 'HIST-001', 'first_name' => 'Fictional', 'last_name' => 'History', 'employer_id' => $e1->id, 'tutor_id' => $old->id];
        $this->post('/apprentices', $data)->assertRedirect()->assertSessionHasNoErrors();
        $a = Apprentice::firstOrFail();
        $this->put('/apprentices/'.$a->id, [...$data, 'version' => 1, 'employer_id' => $e2->id, 'tutor_id' => $new->id])->assertRedirect()->assertSessionHasNoErrors();
        $events = DB::table('assignment_change_events')->get();
        $this->assertCount(2, $events);
        $this->assertSame($old->id, json_decode($events[1]->before_links, true)['tutor_id']);
        $this->assertArrayNotHasKey('email', json_decode($events[1]->after_links, true));
        $summary = AssignmentHistory::summary($a->fresh(), $events);
        $this->assertSame(2, $summary['employers']);
        $this->assertSame(2, $summary['tutors']);
        $this->assertFalse($summary['complete_history']);
        $this->get('/reports/retention')->assertOk()->assertSee('2 distinct recorded linked employers')->assertSee('earlier history unknown');
    }

    public function test_assignment_audit_failure_cannot_leave_a_changed_apprentice(): void
    {
        $a = Apprentice::factory()->create();
        $t = $this->actor('tutor');
        $this->actingAs($this->actor());
        DB::listen(function ($q): void {
            if (str_contains($q->sql, 'assignment_change_events') && str_starts_with(strtolower($q->sql), 'insert')) {
                throw new \RuntimeException('Fictional assignment audit failure');
            }
        });
        $this->put('/apprentices/'.$a->id, ['reference' => $a->reference, 'first_name' => $a->first_name, 'last_name' => $a->last_name, 'version' => 1, 'tutor_id' => $t->id])->assertStatus(500);
        $this->assertNull($a->fresh()->tutor_id);
        $this->assertSame(1, $a->fresh()->version);
    }

    public function test_review_saves_action_outcomes_and_known_next_period_dates_without_creating_targets(): void
    {
        $a = Apprentice::factory()->create();
        $action = SmartAction::factory()->create(['apprentice_id' => $a->id]);
        $task = LearningTask::factory()->create(['apprentice_id' => $a->id, 'due_date' => '2026-12-01']);
        $undated = LearningTask::factory()->create(['apprentice_id' => $a->id, 'reference' => 'b1-t4', 'due_date' => null]);
        $this->actingAs($this->actor())->post('/apprentices/'.$a->id.'/reviews', ['fields' => ['reviewDate' => '2026-11-01', 'summary' => 'Fictional checked preparation']])->assertRedirect()->assertSessionHasNoErrors();
        $review = ProgressReviewDraft::firstOrFail();
        $snapshot = $review->source_snapshot;
        $this->assertSame($action->id, $snapshot['action_plan'][0]['id']);
        $this->assertSame('To do', $snapshot['action_plan'][0]['status']);
        $this->assertSame([$task->id], array_column($snapshot['known_next_period_tasks'], 'id'));
        $this->assertDatabaseCount('smart_actions', 1);
        $action->title = 'Later live change';
        $action->save();
        $this->get('/apprentices/'.$a->id.'/reviews/'.$review->id)->assertOk()->assertSee('Action Plan position captured')->assertSee('Known task deadlines')->assertSee('Complete five practice questions');
        $this->assertSame($snapshot, $review->fresh()->source_snapshot);
    }

    public function test_block_control_audit_failure_rolls_back_exception(): void
    {
        $a = Apprentice::factory()->create();
        $this->actingAs($this->actor());
        DB::listen(function ($q): void {
            if (str_contains($q->sql, 'learning_events') && str_starts_with(strtolower($q->sql), 'insert')) {
                throw new \RuntimeException('Fictional block audit failure');
            }
        });
        $this->post('/apprentices/'.$a->id.'/block-controls', ['version' => 0, 'enabled' => 1, 'operation' => 'release', 'block' => 2, 'reason' => 'Fictional exception'])->assertStatus(500);
        $this->assertDatabaseCount('learning_block_controls', 0);
    }

    public function test_incomplete_source_block_cannot_be_released_by_four_approvals_and_duplicates(): void
    {
        $a = Apprentice::factory()->create();
        LearningBlockControl::create(['apprentice_id' => $a->id, 'exceptions' => [], 'history' => []]);
        foreach (['b1-t1', 'b1-t2', 'b1-t3', 'b1-t4'] as $ref) {
            $t = LearningTask::factory()->create(['apprentice_id' => $a->id, 'reference' => $ref]);
            $t->status = 'Approved';
            $t->save();
        }$next = LearningTask::factory()->create(['apprentice_id' => $a->id, 'reference' => 'b2-t3', 'block' => 2]);
        $this->assertFalse(BlockRelease::state($next)['open']);
    }

    public function test_tutor_can_clear_already_submitted_marking_when_previous_block_is_not_approved(): void
    {
        $t = $this->actor('tutor');
        $a = Apprentice::factory()->create(['tutor_id' => $t->id]);
        LearningBlockControl::create(['apprentice_id' => $a->id, 'exceptions' => [], 'history' => []]);
        $task = LearningTask::factory()->create(['apprentice_id' => $a->id, 'block' => 2, 'reference' => 'b2-t4', 'template' => TaskCatalogue::template('b2-t4')]);
        $task->status = 'Awaiting marking';
        $task->submitted_at = now()->subDay();
        $task->submissions = [['version' => 1, 'submitted_at' => now()->subDay()->toIso8601String(), 'answers' => [], 'decision' => null]];
        $task->save();
        $this->actingAs($t)->post('/tasks/'.$task->id.'/decision', ['version' => 1, 'decision' => 'Approved', 'decision_date' => today()->toDateString(), 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Approved', $task->fresh()->status);
    }

    public function test_operational_report_adds_source_attendance_without_private_support_or_other_apprentices(): void
    {
        $t = $this->actor('tutor');
        $a = Apprentice::factory()->create(['tutor_id' => $t->id, 'reference' => 'VISIBLE-REPORT']);
        $other = Apprentice::factory()->create(['reference' => 'HIDDEN-REPORT']);
        SupportConcern::factory()->create(['apprentice_id' => $a->id, 'initial_note' => 'Private inclusion context never exported']);
        $this->actingAs($t)->get('/reports/operational')->assertOk()->assertSee('Attendance and confirmed training subtotal')->assertSee('VISIBLE-REPORT')->assertDontSee('HIDDEN-REPORT')->assertDontSee('Private inclusion context never exported')->assertDontSee('Open retention source report');
        $empty = AttendanceLedger::forIds([$a->id])[$a->id];
        $this->assertNull($empty['report']['percent']);
        $this->assertSame(0, $empty['report']['expected']);
    }

    public function test_careers_block_ten_prompt_stays_with_assigned_adviser_and_requires_sequence_release(): void
    {
        $adviser = $this->actor('careers_adviser');
        $other = $this->actor('careers_adviser');
        $a = Apprentice::factory()->create(['careers_adviser_id' => $adviser->id, 'reference' => 'CAREER-BLOCK-TEN']);
        LearningTask::factory()->create(['apprentice_id' => $a->id, 'block' => 10, 'reference' => 'b10-t3']);
        LearningBlockControl::create(['apprentice_id' => $a->id, 'exceptions' => ['10' => ['reason' => 'Fictional release']], 'history' => []]);
        $this->actingAs($adviser)->get('/careers')->assertOk()->assertSee('CAREER-BLOCK-TEN')->assertSee('Not recorded — arrange advice');
        $this->actingAs($other)->get('/careers')->assertOk()->assertDontSee('CAREER-BLOCK-TEN');
        $this->actingAs($this->actor('employer'))->get('/careers')->assertForbidden();
    }

    public function test_unchanged_http_string_assignment_ids_do_not_create_duplicate_link_history(): void
    {
        $t = $this->actor('tutor');
        $a = Apprentice::factory()->create(['tutor_id' => $t->id]);
        $this->actingAs($this->actor())->put('/apprentices/'.$a->id, ['reference' => $a->reference, 'first_name' => $a->first_name, 'last_name' => $a->last_name, 'version' => 1, 'tutor_id' => (string) $t->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('assignment_change_events', 0);
    }

    public function test_assigned_autocare_history_cannot_be_silently_relabelled_as_heavy_vehicle(): void
    {
        $a = Apprentice::factory()->create();
        LearningTask::factory()->create(['apprentice_id' => $a->id]);
        $p = Programme::factory()->create(['standard_code' => 'ST0068', 'version' => '1.5']);
        $this->actingAs($this->actor())->put('/apprentices/'.$a->id, ['reference' => $a->reference, 'first_name' => $a->first_name, 'last_name' => $a->last_name, 'version' => 1, 'programme_id' => $p->id])->assertSessionHasErrors('programme_id');
        $this->assertNull($a->fresh()->programme_id);
        $this->assertDatabaseCount('assignment_change_events', 0);
        $this->assertDatabaseCount('learning_tasks', 1);
    }

    public function test_waiting_percentage_stays_unknown_with_a_missing_submission_date(): void
    {
        $t = $this->actor('tutor');
        $a = Apprentice::factory()->create(['tutor_id' => $t->id]);
        $old = LearningTask::factory()->create(['apprentice_id' => $a->id]);
        $old->status = 'Awaiting marking';
        $old->submitted_at = now()->subDays(8);
        $old->save();
        $missing = LearningTask::factory()->create(['apprentice_id' => $a->id, 'reference' => 'b1-t4']);
        $missing->status = 'Awaiting marking';
        $missing->save();
        $r = $this->actingAs($t)->get('/reports/operational')->assertOk()->assertSee('Marking by current main tutor')->assertSee('Unknown — date check');
        $row = $r->viewData('tutorMarking')[0];
        $this->assertSame(1, $row['overdue']);
        $this->assertSame(2, $row['waiting']);
        $this->assertNull($row['waiting_percent']);
        $this->assertSame(1, $row['affected_apprentices']);
    }

    public function test_source_appraisal_fields_reopen_under_existing_hr_visibility_without_adopting_legacy_kpis(): void
    {
        $ceo = $this->actor('ceo');
        $u = $this->actor('staff');
        $this->actingAs($ceo)->post('/staff-development/'.$u->id.'/entry', ['version' => 0, 'kind' => 'appraisal', 'date' => today()->toDateString(), 'title' => 'Fictional source appraisal', 'summary' => 'Fictional checked discussion', 'shared' => 1, 'private_notes' => 'Manager only appraisal context', 'appraisal' => ['achievements' => 'Fictional technical development', 'self_scores' => ['technical' => '7'], 'manager_scores' => ['technical' => 'Not observed']]])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/staff-development/'.$u->id.'?view=manager')->assertOk()->assertSee('Fictional technical development')->assertSee('Manager only appraisal context');
        $this->actingAs($u)->get('/staff-development/'.$u->id)->assertOk()->assertSee('Fictional technical development')->assertSee('Not observed')->assertDontSee('Manager only appraisal context');
        $entry = StaffDevelopmentProfile::where('user_id', $u->id)->first()->payload['entries'][0];
        $this->assertSame('7', $entry['appraisal']['self_scores']['technical']);
        $this->assertArrayNotHasKey('automatic_grade', $entry);
    }

    public function test_source_appraisal_rejects_out_of_range_scores_and_empty_fields_do_not_break_other_record_types(): void
    {
        $ceo = $this->actor('ceo');
        $u = $this->actor('staff');
        $this->actingAs($ceo);
        $base = ['version' => 0, 'kind' => 'appraisal', 'date' => today()->toDateString(), 'title' => 'Fictional source appraisal', 'summary' => 'Fictional discussion'];
        $this->post('/staff-development/'.$u->id.'/entry', [...$base, 'appraisal' => ['manager_scores' => ['technical' => '11']]])->assertSessionHasErrors('appraisal.manager_scores.technical');
        $this->assertDatabaseCount('staff_development_profiles', 0);
        $this->post('/staff-development/'.$u->id.'/entry', [...$base, 'kind' => 'one-to-one', 'appraisal' => ['achievements' => '', 'manager_scores' => ['technical' => '']]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([], StaffDevelopmentProfile::where('user_id', $u->id)->first()->payload['entries'][0]['appraisal']);
    }
}
