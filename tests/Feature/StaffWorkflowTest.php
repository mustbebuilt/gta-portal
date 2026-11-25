<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\AttendanceMark;
use App\Models\LearningBlockControl;
use App\Models\LearningTask;
use App\Models\ProgrammeOutcome;
use App\Models\ProgressReviewDraft;
use App\Models\ReviewPreparationBrief;
use App\Models\SignedReviewRecord;
use App\Models\SmartAction;
use App\Models\TrainingSession;
use App\Models\User;
use App\Support\ApprenticeExperience;
use App\Support\ReviewBriefs;
use App\Support\ReviewedImports;
use App\Support\ReviewSnapshot;
use App\Support\StaffAttention;
use App\Support\StaffTrainingRecords;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\AuditFault;
use Tests\TestCase;

class StaffWorkflowTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-14 12:00:00'));
    }

    private function fixture(bool $active = true): array
    {
        $u = User::factory()->create(['role' => 'tutor']);
        $m = User::factory()->create(['role' => 'mentor']);
        $learner = User::factory()->create(['role' => 'apprentice']);
        $a = Apprentice::factory()->create(['tutor_id' => $u->id, 'mentor_id' => $m->id, 'user_id' => $learner->id, 'start_date' => today()->subDays(100)]);
        if ($active) {
            ProgrammeOutcome::factory()->create(['apprentice_id' => $a->id, 'context' => ['status' => 'Active', 'sourceRef' => 'Fictional source']]);
        }
        $r = ProgressReviewDraft::factory()->create(['apprentice_id' => $a->id, 'author_id' => $u->id]);

        return [$u, $a, $r, $m, $learner];
    }

    private function period(ProgressReviewDraft $r): array
    {
        return ['version' => $r->version, 'period_from' => today()->subDays(60)->toDateString(), 'period_to' => today()->toDateString()];
    }

    private function brief(User $u, ProgressReviewDraft $r): ReviewPreparationBrief
    {
        return ReviewBriefs::prepare($u, $u->auth_version, $r, $this->period($r));
    }

    private function applyData(ProgressReviewDraft $r, array $selected = ['summary']): array
    {
        return ['version' => $r->version, 'confirmed' => 1, 'selected' => $selected, 'text' => ['summary' => 'Checked factual summary', 'learning' => 'Checked learning', 'previousNotes' => 'Checked previous actions', 'progressDiscussion' => 'Checked progress']];
    }

    private function url(Apprentice $a, ProgressReviewDraft $r, ?ReviewPreparationBrief $b = null): string
    {
        return route($b ? 'review-briefs.apply' : 'review-briefs.prepare', $b ? [$a, $r, $b] : [$a, $r]);
    }

    private function rosterSession(Apprentice $a, User $u, string $date, ?string $code): TrainingSession
    {
        $s = TrainingSession::factory()->create(['members' => [$a->id], 'session_date' => $date]);
        if ($code !== null) {
            AttendanceMark::create(['training_session_id' => $s->id, 'apprentice_id' => $a->id, 'code' => $code, 'recorded_by' => $u->id, 'comment' => 'PRIVATE-ATTENDANCE-COMMENT']);
        }

        return $s;
    }

    private function signed(Apprentice $a, ProgressReviewDraft $r, User $u, int $days): void
    {
        $date = today()->subDays($days)->toDateString();
        $hash = str_repeat('a', 64);
        $s = ['apprentice_id' => $a->id, 'review_id' => $r->id, 'review_version' => 1, 'file_sha256' => $hash, 'fields' => ['reviewDate' => $date]];
        SignedReviewRecord::create(['apprentice_id' => $a->id, 'progress_review_draft_id' => $r->id, 'review_version' => 1, 'meeting_date' => $date, 'snapshot' => $s, 'snapshot_hash' => ReviewedImports::hash($s), 'file' => ['sha256' => $hash], 'prepared_by' => $u->id, 'checked_by' => User::factory()->create(['role' => 'admin'])->id, 'checked_at' => now(), 'history' => []]);
    }

    public function test_attention_denies_unpermitted_roles_and_inactive_accounts(): void
    {
        foreach (['apprentice', 'employer', 'staff', 'manager', 'functional_skills_tutor', 'careers_adviser', 'inclusion_member'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get('/needs-attention')->assertForbidden();
        }$this->actingAs(User::factory()->create(['role' => 'admin', 'active' => false]))->get('/needs-attention')->assertForbidden();
    }

    public function test_attention_scope_and_get_leave_source_records_unchanged(): void
    {
        [$u,$a] = $this->fixture();
        LearningTask::factory()->create(['apprentice_id' => $a->id, 'due_date' => today()->subDay()]);
        [, $outside] = $this->fixture();
        $outside->first_name = 'UNRELATED-SECRET';
        $outside->save();
        $before = DB::table('learning_tasks')->get()->toJson();
        $this->actingAs($u)->get('/needs-attention')->assertOk()->assertSee($a->reference)->assertDontSee('UNRELATED-SECRET')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($before, DB::table('learning_tasks')->get()->toJson());
        $this->assertDatabaseCount('learning_events', 0);
    }

    public function test_one_row_combines_multiple_reasons_and_counts_people(): void
    {
        [$u,$a] = $this->fixture();
        LearningTask::factory()->create(['apprentice_id' => $a->id, 'due_date' => today()->subDay()]);
        SmartAction::factory()->create(['apprentice_id' => $a->id, 'due_date' => today()->subDays(2)]);
        $p = StaffAttention::for($u);
        $this->assertCount(1, $p['rows']);
        $this->assertSame(1, $p['counts']['overdue']);
        $this->assertGreaterThanOrEqual(3, count($p['rows'][0]['reasons']));
        $this->actingAs($u)->get('/needs-attention?reason=action&view=overdue')->assertOk()->assertSee('Agreed action')->assertDontSee('Task due:');
    }

    public function test_attention_groups_two_reasons_that_open_the_same_attendance_record(): void
    {
        [$tutor, $apprentice] = $this->fixture();
        $this->rosterSession($apprentice, $tutor, today()->subDays(2)->toDateString(), 'A');
        $this->rosterSession($apprentice, $tutor, today()->subDay()->toDateString(), 'S');
        $page = $this->actingAs($tutor)->get(route('attention.index', ['reason'=>'attendance']))
            ->assertOk()->assertSee('Other reasons and follow-up');
        $document = new \DOMDocument;
        $document->loadHTML($page->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $path = new \DOMXPath($document);
        $section = $path->query('//section[./p="'.$apprentice->reference.'"]')->item(0);
        $links = $path->query('.//a[@href="'.route('attendance.index').'"]', $section);
        $this->assertCount(1, $links);
        $this->assertSame('Open attendance record', trim($links->item(0)->textContent));
        $this->assertCount(1, $path->query('.//details//li[contains(., "Recorded missed session")]', $section));
    }

    public function test_unknown_and_paused_status_do_not_create_training_overdue_claims(): void
    {
        [$u,$a] = $this->fixture(false);
        LearningTask::factory()->create(['apprentice_id' => $a->id, 'due_date' => today()->subDays(20)]);
        $p = StaffAttention::for($u);
        $this->assertSame(0, $p['counts']['overdue']);
        $this->assertSame('programme', $p['rows'][0]['reasons'][0]['kind']);
        ProgrammeOutcome::factory()->create(['apprentice_id' => $a->id, 'context' => ['status' => 'Break in learning', 'sourceRef' => 'Fictional checked status']]);
        $this->assertCount(0, StaffAttention::for($u)['rows']);
    }

    public function test_review_due_reference_requires_checked_signed_history(): void
    {
        [$u,$a,$r] = $this->fixture();
        $p = StaffAttention::for($u);
        $this->assertSame('checks', $p['rows'][0]['bucket']);
        $this->signed($a, $r, $u, 80);
        $p = StaffAttention::for($u);
        $this->assertSame('overdue', $p['rows'][0]['bucket']);
        $this->assertSame(today()->subDays(10)->toDateString(), $p['rows'][0]['reasons'][0]['date']);
    }

    public function test_mentor_gets_review_reason_but_not_training_or_private_help(): void
    {
        [$u,$a,$r,$m,$learner] = $this->fixture();
        LearningTask::factory()->create(['apprentice_id' => $a->id, 'due_date' => today()->subDay(), 'title' => 'TRAINING-SECRET']);
        ApprenticeExperience::create($learner, $learner->auth_version, ['request_token' => (string) Str::uuid(), 'kind' => 'help_private', 'title' => 'PRIVATE-HELP', 'detail' => 'PRIVATE-HELP-BODY']);
        $p = StaffAttention::for($m);
        $this->assertSame(['review'], array_column($p['rows'][0]['reasons'], 'kind'));
        $this->actingAs($m)->get('/needs-attention')->assertOk()->assertDontSee('TRAINING-SECRET')->assertDontSee('PRIVATE-HELP');
    }

    public function test_new_non_private_help_and_achievement_have_dedicated_links(): void
    {
        [$u,$a,$r,$m,$learner] = $this->fixture(false);
        $e = ApprenticeExperience::create($learner, $learner->auth_version, ['request_token' => (string) Str::uuid(), 'kind' => 'help_task', 'title' => 'Fictional task help', 'detail' => 'Fictional request']);
        $this->actingAs($u)->get('/needs-attention?reason=help')->assertOk()->assertSee('#entry-'.$e->id, false)->assertSee('awaiting first staff response');
        $e->state = 'Received';
        $e->save();
        $this->get('/needs-attention?reason=help')->assertDontSee('awaiting first staff response');
    }

    public function test_missing_and_blocked_task_dates_are_checks_not_overdue(): void
    {
        [$u,$a] = $this->fixture();
        $t = LearningTask::factory()->create(['apprentice_id' => $a->id, 'block' => 2, 'due_date' => today()->subDay()]);
        LearningBlockControl::create(['apprentice_id' => $a->id, 'enabled' => true, 'exceptions' => [], 'history' => []]);
        $p = StaffAttention::for($u);
        $reasons = collect($p['rows'][0]['reasons'])->where('kind', 'task');
        $this->assertSame('checks', $reasons->first()['bucket']);
        $t->due_date = null;
        $t->save();
        $this->assertStringContainsString('deadline not recorded', collect(StaffAttention::for($u)['rows'][0]['reasons'])->firstWhere('kind', 'task')['text']);
    }

    public function test_marking_threshold_and_linked_action_do_not_duplicate_task(): void
    {
        [$u,$a] = $this->fixture();
        $t = LearningTask::factory()->create(['apprentice_id' => $a->id, 'status' => 'Awaiting marking']);
        $t->submitted_at = now()->subDays(8);
        $t->save();
        SmartAction::factory()->create(['apprentice_id' => $a->id, 'learning_task_id' => $t->id, 'due_date' => today()->subDays(10)]);
        $reasons = collect(StaffAttention::for($u)['rows'][0]['reasons']);
        $this->assertCount(1, $reasons->where('kind', 'marking'));
        $this->assertCount(0, $reasons->where('kind', 'action'));
    }

    public function test_consecutive_complete_weeks_flag_attendance_but_unmarked_week_breaks_pair(): void
    {
        [$u,$a] = $this->fixture();
        $one = today()->startOfWeek()->subWeeks(2)->addDay();
        $two = $one->copy()->addWeek();
        $this->rosterSession($a, $u, $one->toDateString(), 'A');
        $s = $this->rosterSession($a, $u, $two->toDateString(), 'S');
        $r = collect(StaffAttention::for($u)['rows'][0]['reasons'])->where('kind', 'attendance');
        $this->assertSame('overdue', $r->first()['bucket']);
        AttendanceMark::where('training_session_id', $s->id)->update(['code' => null]);
        $r = collect(StaffAttention::for($u)['rows'][0]['reasons'])->where('kind', 'attendance');
        $this->assertCount(0, $r->where('bucket', 'overdue'));
        $this->assertGreaterThan(0, $r->where('bucket', 'checks')->count());
    }

    public function test_unrelated_malformed_roster_does_not_break_own_attention(): void
    {
        [$u,$a] = $this->fixture();
        TrainingSession::factory()->create(['members' => ['not-a-profile'], 'session_date' => today()->subDay()]);
        $this->actingAs($u)->get('/needs-attention')->assertOk();
        $this->assertSame([], StaffTrainingRecords::attendance($a, today()->subWeek()->toDateString(), today()->toDateString()));
    }

    public function test_progress_gap_is_only_a_reliable_dated_prompt(): void
    {
        [$u,$a] = $this->fixture();
        $t = LearningTask::factory()->create(['apprentice_id' => $a->id]);
        $r = collect(StaffAttention::for($u)['rows'][0]['reasons'])->firstWhere('kind', 'progress');
        $this->assertSame('followup', $r['bucket']);
        $approved = LearningTask::factory()->create(['apprentice_id' => $a->id, 'reference' => 'b1-t4', 'status' => 'Approved']);
        $r = collect(StaffAttention::for($u)['rows'][0]['reasons'])->firstWhere('kind', 'progress');
        $this->assertSame('checks', $r['bucket']);
        $approved->submissions = [['decision' => ['date' => today()->subDay()->toDateString()]]];
        $approved->save();
        $this->assertNull(collect(StaffAttention::for($u)['rows'][0]['reasons'])->firstWhere('kind', 'progress'));
    }

    public function test_attention_filters_validation_and_pagination_stay_scoped(): void
    {
        [$u] = $this->fixture(false);
        for ($i = 0; $i < 16; $i++) {
            Apprentice::factory()->create(['tutor_id' => $u->id]);
        }$this->actingAs($u)->get('/needs-attention?view=checks&page=999')->assertOk()->assertViewHas('rows', fn ($x) => $x->currentPage() === 2 && $x->total() === 17);
        $this->get('/needs-attention?q=NO-SUCH-PERSON')->assertOk()->assertSee('0 apprentices match');
        $this->get('/needs-attention?view=invalid')->assertSessionHasErrors('view');
        $this->get('/needs-attention?page=-2')->assertSessionHasErrors('page');
    }

    public function test_preparation_captures_safe_sources_without_touching_draft(): void
    {
        [$u,$a,$r] = $this->fixture();
        $t = LearningTask::factory()->create(['apprentice_id' => $a->id, 'status' => 'Approved']);
        $t->submissions = [['decision' => ['date' => today()->subDay()->toDateString(), 'feedback' => 'SHAREABLE TRAINING FEEDBACK']]];
        $t->deadline_history = [['reason' => 'PRIVATE-DEADLINE-REASON']];
        $t->save();
        $this->rosterSession($a, $u, today()->subDay()->toDateString(), 'O');
        SmartAction::factory()->create(['apprentice_id' => $a->id, 'support' => 'PRIVATE-ACTION-SUPPORT']);
        $before = $r->fresh()->getAttributes();
        $b = $this->brief($u, $r);
        $this->assertSame($before, $r->fresh()->getAttributes());
        $this->assertTrue(ReviewBriefs::intact($b));
        $raw = json_encode($b->source);
        $this->assertStringContainsString('SHAREABLE TRAINING FEEDBACK', $raw);
        foreach (['PRIVATE-DEADLINE-REASON', 'PRIVATE-ATTENDANCE-COMMENT', 'PRIVATE-ACTION-SUPPORT'] as $text) {
            $this->assertStringNotContainsString($text, $raw);
        }$this->assertStringContainsString('preparation', strtolower($b->suggested['summary']));
        $this->assertDatabaseCount('review_preparation_briefs', 1);
    }

    public function test_preparation_page_and_saved_review_link_render(): void
    {
        [$u,$a,$r] = $this->fixture();
        $r->source_snapshot = ReviewSnapshot::capture($a, []);
        $r->save();
        $this->actingAs($u)->get(route('reviews.edit', [$a, $r]))->assertOk()->assertSee('Prepare factual review notes');
        $this->get(route('review-briefs.index', [$a, $r]))->assertOk()->assertSee('Generate factual preparation notes');
        $this->post($this->url($a, $r), $this->period($r))->assertRedirect()->assertSessionHasNoErrors();
        $b = ReviewPreparationBrief::first();
        $this->get(route('review-briefs.show', [$a, $r, $b]))->assertOk()->assertSee('Proposed notes')->assertSee('Use this checked section');
    }

    public function test_source_period_limits_decisions_and_attendance_while_current_counts_are_labelled(): void
    {
        [$u,$a,$r] = $this->fixture();
        $t = LearningTask::factory()->create(['apprentice_id' => $a->id, 'status' => 'Approved']);
        $t->submissions = [['decision' => ['date' => today()->subDays(90)->toDateString(), 'feedback' => 'OUTSIDE-PERIOD']]];
        $t->save();
        $this->rosterSession($a, $u, today()->subDays(90)->toDateString(), 'A');
        $b = $this->brief($u, $r);
        $this->assertStringNotContainsString('OUTSIDE-PERIOD', $b->suggested['progressDiscussion']);
        $this->assertNull($b->source['tasks'][0]['feedback']);
        $this->assertSame([], $b->source['attendance']);
        $this->assertStringContainsString('1 approved of 1 allocated', $b->suggested['progressDiscussion']);
        $this->assertStringContainsString('not a historical', $b->suggested['progressDiscussion']);
    }

    public function test_missing_sources_produce_unknowns_without_inventing_a_judgement_or_meeting(): void
    {
        [$u,$a,$r] = $this->fixture();
        $b = $this->brief($u, $r);
        $this->assertStringContainsString('percentage unavailable', $b->suggested['summary']);
        $this->assertStringContainsString('do not assume this was a first review', $b->suggested['previousNotes']);
        $this->assertArrayNotHasKey('judgement', $b->suggested);
        $this->assertArrayNotHasKey('reviewDate', $b->suggested);
    }

    public function test_selected_edited_notes_apply_once_and_reset_manual_confirmations(): void
    {
        [$u,$a,$r] = $this->fixture();
        $r->fields = ['learning' => 'KEEP EXISTING LEARNING', 'judgement' => 'Behind plan', 'reviewDate' => '2026-11-10', 'providerPrepared' => true, 'trainingChecked' => true, 'targetsAgreed' => true];
        $r->save();
        $b = $this->brief($u, $r);
        $this->actingAs($u)->post($this->url($a, $r, $b), $this->applyData($r))->assertRedirect()->assertSessionHasNoErrors();
        $r->refresh();
        $this->assertSame('Checked factual summary', $r->fields['summary']);
        $this->assertSame('KEEP EXISTING LEARNING', $r->fields['learning']);
        $this->assertSame('Behind plan', $r->fields['judgement']);
        $this->assertSame('2026-11-10', $r->fields['reviewDate']);
        foreach (['providerPrepared', 'trainingChecked', 'targetsAgreed'] as $key) {
            $this->assertFalse($r->fields[$key]);
        }$this->assertSame('Draft', $r->state);
        $this->assertSame(2, $r->version);
        $this->assertCount(1, $r->history);
        $this->assertSame($b->id, $r->source_snapshot['assistant_preparation']['brief_id']);
        $this->assertSame(['summary' => 'Checked factual summary'], $b->fresh()->applied);
        $this->post($this->url($a, $r, $b), $this->applyData($r))->assertSessionHasErrors('brief');
        $this->assertSame(2, $r->fresh()->version);
    }

    public function test_existing_text_requires_explicit_replacement_and_history_keeps_it(): void
    {
        [$u,$a,$r] = $this->fixture();
        $r->fields = ['summary' => 'ORIGINAL DISCUSSION'];
        $r->save();
        $b = $this->brief($u, $r);
        $this->actingAs($u)->post($this->url($a, $r, $b), $this->applyData($r))->assertSessionHasErrors('brief');
        $this->assertNull($b->fresh()->applied_at);
        $this->post($this->url($a, $r, $b), $this->applyData($r) + ['replace_existing' => 1])->assertRedirect();
        $this->assertSame('ORIGINAL DISCUSSION', $r->fresh()->history[0]['fields']['summary']);
    }

    public function test_no_automatic_apply_on_get_or_prepare_and_selection_is_required(): void
    {
        [$u,$a,$r] = $this->fixture();
        $b = $this->brief($u, $r);
        $this->actingAs($u)->get(route('review-briefs.show', [$a, $r, $b]))->assertOk();
        $this->assertSame(1, $r->fresh()->version);
        $this->post($this->url($a, $r, $b), array_replace($this->applyData($r), ['selected' => []]))->assertSessionHasErrors('selected');
        $data = $this->applyData($r);
        unset($data['confirmed']);
        $this->post($this->url($a, $r, $b), $data)->assertSessionHasErrors('confirmed');
        $this->assertSame(1, $r->fresh()->version);
    }

    public function test_apply_rejects_forged_fields_blank_text_and_oversize(): void
    {
        [$u,$a,$r] = $this->fixture();
        $b = $this->brief($u, $r);
        $this->actingAs($u);
        $this->post($this->url($a, $r, $b), array_replace($this->applyData($r), ['selected' => ['judgement']]))->assertSessionHasErrors('selected.0');
        $this->post($this->url($a, $r, $b), array_replace($this->applyData($r), ['text' => ['summary' => '']]))->assertSessionHasErrors('brief');
        $this->post($this->url($a, $r, $b), array_replace($this->applyData($r), ['text' => ['summary' => str_repeat('x', 20001)]]))->assertSessionHasErrors('text.summary');
        $this->assertSame(1, $r->fresh()->version);
    }

    public function test_task_action_register_and_day_changes_make_capture_stale(): void
    {
        foreach (['task', 'action', 'register', 'day'] as $change) {
            [$u,$a,$r] = $this->fixture();
            $t = LearningTask::factory()->create(['apprentice_id' => $a->id]);
            $x = SmartAction::factory()->create(['apprentice_id' => $a->id]);
            $s = $this->rosterSession($a, $u, today()->subDay()->toDateString(), 'O');
            $b = $this->brief($u, $r);
            if ($change === 'task') {
                $t->version++;
                $t->save();
            } elseif ($change === 'action') {
                $x->title = 'Changed action';
                $x->save();
            } elseif ($change === 'register') {
                AttendanceMark::where('training_session_id', $s->id)->update(['code' => 'A']);
            } else {
                $this->travel(1)->days();
            }$this->actingAs($u)->post($this->url($a, $r, $b), $this->applyData($r))->assertSessionHasErrors('brief');
            $this->assertSame(1, $r->fresh()->version);
            $this->travelTo(Carbon::parse('2026-11-14 12:00:00'));
        }
    }

    public function test_saved_review_change_and_frozen_state_block_old_capture(): void
    {
        [$u,$a,$r] = $this->fixture();
        $b = $this->brief($u, $r);
        $r->fields = ['summary' => 'NEWER USER TEXT'];
        $r->version++;
        $r->save();
        $this->actingAs($u)->post($this->url($a, $r, $b), $this->applyData($r))->assertSessionHasErrors('brief');
        $r->state = 'Ready for signatures';
        $r->save();
        $this->post($this->url($a, $r), $this->period($r))->assertSessionHasErrors('brief');
        $this->post($this->url($a, $r, $b), $this->applyData($r))->assertSessionHasErrors('brief');
        $this->assertSame('NEWER USER TEXT', $r->fresh()->fields['summary']);
    }

    public function test_reassignment_and_unrelated_roles_cannot_read_or_apply_preparation(): void
    {
        [$u,$a,$r,$m,$learner] = $this->fixture();
        $b = $this->brief($u, $r);
        foreach ([$learner, User::factory()->create(['role' => 'employer']), User::factory()->create(['role' => 'tutor']), User::factory()->create(['role' => 'functional_skills_tutor'])] as $other) {
            $this->actingAs($other)->get(route('review-briefs.show', [$a, $r, $b]))->assertForbidden();
            $this->post($this->url($a, $r, $b), $this->applyData($r))->assertForbidden();
        }$a->tutor_id = null;
        $a->save();
        $this->actingAs($u)->post($this->url($a, $r, $b), $this->applyData($r))->assertForbidden();
    }

    public function test_assigned_mentor_and_management_can_prepare_under_existing_review_scope(): void
    {
        [$u,$a,$r,$m] = $this->fixture();
        $this->actingAs($m)->post($this->url($a, $r), $this->period($r))->assertRedirect()->assertSessionHasNoErrors();
        $b = ReviewPreparationBrief::first();
        $this->get(route('review-briefs.show', [$a, $r, $b]))->assertOk()->assertDontSee('/tasks/', false);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('review-briefs.show', [$a, $r, $b]))->assertOk();
    }

    public function test_cross_profile_and_cross_review_route_binding_is_refused(): void
    {
        [$u,$a,$r] = $this->fixture();
        $b = $this->brief($u, $r);
        $other = Apprentice::factory()->create(['tutor_id' => $u->id]);
        $second = ProgressReviewDraft::factory()->create(['apprentice_id' => $a->id]);
        $this->actingAs($u)->get(route('review-briefs.show', [$other, $r, $b]))->assertNotFound();
        $this->get(route('review-briefs.show', [$a, $second, $b]))->assertNotFound();
        $this->post($this->url($a, $second, $b), $this->applyData($second))->assertNotFound();
    }

    public function test_capture_tampering_hides_text_and_blocks_apply(): void
    {
        [$u,$a,$r] = $this->fixture();
        $b = $this->brief($u, $r);
        $b->suggested = ['summary' => 'TAMPERED-INVISIBLE-TEXT'];
        $b->save();
        $this->actingAs($u)->get(route('review-briefs.show', [$a, $r, $b]))->assertOk()->assertSee('failed its integrity check')->assertDontSee('TAMPERED-INVISIBLE-TEXT');
        $this->post($this->url($a, $r, $b), $this->applyData($r))->assertSessionHasErrors('brief');
        $this->assertNull($b->fresh()->applied_at);
    }

    public function test_audit_failure_rolls_back_draft_brief_apply_and_history(): void
    {
        [$u,$a,$r] = $this->fixture();
        $b = $this->brief($u, $r);
        AuditFault::install('brief_apply_audit_failure', 'learning_events', 'Fictional failure');
        $this->actingAs($u)->post($this->url($a, $r, $b), $this->applyData($r))->assertStatus(500);
        $this->assertSame(1, $r->fresh()->version);
        $this->assertNull($b->fresh()->applied_at);
        $this->assertNull($r->fresh()->history);
        $this->post($this->url($a, $r), $this->period($r))->assertStatus(500);
        $this->assertDatabaseCount('review_preparation_briefs', 1);
    }

    public function test_period_validation_rejects_future_reversed_and_overlong_windows(): void
    {
        [$u,$a,$r] = $this->fixture();
        $this->actingAs($u);
        foreach ([['period_to' => today()->addDay()->toDateString()], ['period_from' => today()->addDay()->toDateString()], ['period_from' => today()->subYears(2)->toDateString()], ['period_from' => 'not-a-date']] as $change) {
            $this->post($this->url($a, $r), array_replace($this->period($r), $change))->assertSessionHasErrors();
        }$this->assertDatabaseCount('review_preparation_briefs', 0);
    }

    public function test_capture_content_is_escaped_and_private_case_tables_are_not_used(): void
    {
        [$u,$a,$r,$m,$learner] = $this->fixture();
        LearningTask::factory()->create(['apprentice_id' => $a->id, 'title' => '<script>TRAINING-TITLE</script>']);
        ApprenticeExperience::create($learner, $learner->auth_version, ['request_token' => (string) Str::uuid(), 'kind' => 'help_private', 'title' => 'PRIVATE-SUPPORT-SECRET', 'detail' => 'PRIVATE-CASE-TEXT']);
        $b = $this->brief($u, $r);
        $this->actingAs($u)->get(route('review-briefs.show', [$a, $r, $b]))->assertOk()->assertSee('&lt;script&gt;TRAINING-TITLE&lt;/script&gt;', false)->assertDontSee('<script>TRAINING-TITLE</script>', false)->assertDontSee('PRIVATE-SUPPORT-SECRET')->assertDontSee('PRIVATE-CASE-TEXT');
    }

    public function test_migration_round_trip_retains_prior_experience_and_sms_schema(): void
    {
        [$u,$a,$r] = $this->fixture();
        $m = require database_path('migrations/2026_11_14_220000_create_review_preparation_briefs.php');
        $m->down();
        $this->assertFalse(Schema::hasTable('review_preparation_briefs'));
        $this->assertTrue(Schema::hasTable('apprentice_experience_entries'));
        $this->assertTrue(Schema::hasColumn('task_evidence', 'description'));
        $this->assertTrue(Schema::hasTable('apprentice_mobile_challenges'));
        $m->up();
        $this->assertTrue(Schema::hasTable('review_preparation_briefs'));
        $this->assertSame(1, $r->fresh()->version);
    }

    public function test_private_help_does_not_change_combined_counts_or_reasons(): void
    {
        [$u,$a,$r,$m,$learner] = $this->fixture();
        $before = StaffAttention::for($u);
        ApprenticeExperience::create($learner, $learner->auth_version, ['request_token' => (string) Str::uuid(), 'kind' => 'help_private', 'title' => 'Private', 'detail' => 'Private body']);
        $after = StaffAttention::for($u);
        $this->assertSame($before['counts'], $after['counts']);
        $this->assertSame($before['rows'][0]['reasons'], $after['rows'][0]['reasons']);
    }

    public function test_due_today_marking_and_numeric_string_rosters_are_supported(): void
    {
        [$u,$a] = $this->fixture();
        $t = LearningTask::factory()->create(['apprentice_id' => $a->id, 'status' => 'Awaiting marking']);
        $t->submitted_at = now()->subDays(7);
        $t->save();
        $reason = collect(StaffAttention::for($u)['rows'][0]['reasons'])->firstWhere('kind', 'marking');
        $this->assertSame('today', $reason['bucket']);
        $s = $this->rosterSession($a, $u, today()->subDay()->toDateString(), 'O');
        $s->members = [(string) $a->id];
        $s->save();
        $rows = StaffTrainingRecords::attendance($a, today()->subWeek()->toDateString(), today()->toDateString());
        $this->assertCount(1, $rows);
        $this->assertSame('O', $rows[0]['code']);
    }

    public function test_excluded_cancelled_and_unmarked_sessions_are_not_fabricated_absence(): void
    {
        [$u,$a,$r] = $this->fixture();
        foreach (['B', 'C', 'E', 'O'] as $i => $code) {
            $this->rosterSession($a, $u, today()->subDays($i + 1)->toDateString(), $code);
        }$this->rosterSession($a, $u, today()->subDays(5)->toDateString(), null);
        $s = $this->rosterSession($a, $u, today()->subDays(6)->toDateString(), 'A');
        $s->state = 'Cancelled';
        $s->save();
        $b = $this->brief($u, $r);
        $this->assertStringContainsString('0 absent/holiday/sickness/kept at work', $b->suggested['summary']);
        $this->assertStringContainsString('3 excluded', $b->suggested['summary']);
        $this->assertStringContainsString('1 cancelled', $b->suggested['summary']);
        $this->assertStringContainsString('percentage unavailable', $b->suggested['summary']);
    }

    public function test_service_rechecks_actor_auth_version_at_prepare_and_apply(): void
    {
        [$u,$a,$r] = $this->fixture();
        try {
            ReviewBriefs::prepare($u, $u->auth_version + 1, $r, $this->period($r));
            $this->fail('Stale actor accepted');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }$b = $this->brief($u, $r);
        try {
            ReviewBriefs::apply($u, $u->auth_version + 1, $b, $this->applyData($r));
            $this->fail('Stale actor accepted');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }$this->assertNull($b->fresh()->applied_at);
    }

    public function test_preparation_budget_is_stored_across_requests(): void
    {
        [$u,$a,$r] = $this->fixture();
        $first = $this->brief($u, $r);
        for ($i = 1; $i < 50; $i++) {
            $copy = $first->replicate();
            $copy->id = (string) Str::uuid();
            $copy->save();
        }$this->actingAs($u)->post($this->url($a, $r), $this->period($r))->assertSessionHasErrors('brief');
        $this->assertDatabaseCount('review_preparation_briefs', 50);
    }

    public function test_invalid_saved_roster_withholds_percentage_and_is_a_source_check(): void
    {
        [$u,$a,$r] = $this->fixture();
        $s = $this->rosterSession($a, $u, today()->subDay()->toDateString(), 'O');
        $s->members = [$a->id, $a->id];
        $s->save();
        $report = StaffTrainingRecords::report(StaffTrainingRecords::attendance($a, today()->subWeek()->toDateString(), today()->toDateString()));
        $this->assertNull($report['percent']);
        $this->assertSame(1, $report['invalid_sources']);
        $this->assertSame(1, $report['unmarked']);
        $this->actingAs($u)->get('/needs-attention?reason=attendance')->assertOk()->assertSee('not assumed absence');
    }

    public function test_cross_profile_action_task_link_does_not_import_foreign_task_data(): void
    {
        [$u,$a,$r] = $this->fixture();
        $foreign = LearningTask::factory()->create(['due_date' => '2026-12-31', 'status' => 'Approved']);
        SmartAction::factory()->create(['apprentice_id' => $a->id, 'learning_task_id' => $foreign->id]);
        $b = $this->brief($u, $r);
        $this->assertSame('Source needs checking', $b->source['actions'][0]['status']);
        $this->assertNull($b->source['actions'][0]['due']);
        $this->actingAs($u)->post($this->url($a, $r, $b), $this->applyData($r))->assertSessionHasErrors('brief');
        $this->assertSame(1, $r->fresh()->version);
    }

    public function test_invalid_calendar_dates_are_not_attributed_to_the_period(): void
    {
        [$u,$a,$r] = $this->fixture();
        $t = LearningTask::factory()->create(['apprentice_id' => $a->id, 'status' => 'Approved']);
        $t->submissions = [['decision' => ['date' => '2026-02-31', 'feedback' => 'INVALID-DATED-FEEDBACK']]];
        $t->save();
        $b = $this->brief($u, $r);
        $this->assertNull($b->source['tasks'][0]['decision_date']);
        $this->assertNull($b->source['tasks'][0]['feedback']);
        $this->assertFalse(StaffTrainingRecords::validDate('2026-02-31'));
    }
}
