<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\MonthlyScorecard;
use App\Models\ProgrammeOutcome;
use App\Models\ProgressReviewDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class RetentionScorecardTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role = 'admin'): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->save();

        return $u;
    }

    public function test_completed_programme_does_not_automatically_mean_achieved(): void
    {
        $a = Apprentice::factory()->create(['start_date' => '2026-01-01']);
        $this->actingAs($this->actor())->post('/apprentices/'.$a->id.'/outcome', ['version' => 0, 'context' => ['status' => 'Completed', 'endDate' => '2026-10-01', 'sourceRef' => 'Fictional completion record']])->assertRedirect()->assertSessionHasNoErrors();
        $o = ProgrammeOutcome::firstOrFail();
        $this->assertSame('Unknown', $o->context['achievement']);
        $this->get('/reports/retention')->assertOk()->assertSee('Completed')->assertSee('Achievement Unknown');
    }

    public function test_completion_end_date_and_achievement_date_require_sourced_chronology(): void
    {
        $a = Apprentice::factory()->create(['start_date' => '2026-01-01']);
        $this->actingAs($this->actor())->post('/apprentices/'.$a->id.'/outcome', ['version' => 0, 'context' => ['status' => 'Completed', 'endDate' => '2025-12-01', 'sourceRef' => 'REF']])->assertSessionHasErrors('context.endDate');
        $this->post('/apprentices/'.$a->id.'/outcome', ['version' => 0, 'context' => ['status' => 'Completed', 'endDate' => '2026-10-01', 'achievement' => 'Achieved', 'achievementDate' => '2026-09-01', 'sourceRef' => 'REF']])->assertSessionHasErrors('context.achievementDate');
        $this->assertDatabaseCount('programme_outcomes', 0);
    }

    public function test_withdrawal_reason_and_support_context_need_basis_and_source(): void
    {
        $a = Apprentice::factory()->create(['start_date' => '2026-01-01']);
        $this->actingAs($this->actor())->post('/apprentices/'.$a->id.'/outcome', ['version' => 0, 'context' => ['status' => 'Withdrawn', 'endDate' => '2026-10-01', 'reason' => 'Employment ended', 'sourceRef' => 'REF']])->assertSessionHasErrors('context.reasonBasis');
        $this->post('/apprentices/'.$a->id.'/outcome', ['version' => 0, 'context' => ['additional' => 'No', 'ehcp' => 'Yes', 'contextRef' => 'REF']])->assertSessionHasErrors('context.additional');
    }

    public function test_employer_headcount_needs_employer_date_and_source(): void
    {
        $a = Apprentice::factory()->create();
        $this->actingAs($this->actor())->post('/apprentices/'.$a->id.'/outcome', ['version' => 0, 'context' => ['size' => '10–49']])->assertSessionHasErrors('context.size');
    }

    public function test_retention_context_is_admin_only_and_unknown_history_stays_unknown(): void
    {
        $a = Apprentice::factory()->create();
        $this->actingAs($this->actor('tutor'))->get('/reports/retention')->assertForbidden();
        $this->post('/apprentices/'.$a->id.'/outcome', ['version' => 0, 'context' => ['status' => 'Active']])->assertForbidden();
        $this->actingAs($this->actor())->get('/reports/retention')->assertOk()->assertSee('Unknown — full history not connected')->assertSee('not a lifetime concern count');
        $this->get('/apprentices/'.$a->id.'/outcome')->assertOk();
    }

    public function test_stale_outcome_and_unsupported_source_fields_cannot_replace_current(): void
    {
        $a = Apprentice::factory()->create();
        $this->actingAs($this->actor())->post('/apprentices/'.$a->id.'/outcome', ['version' => 0, 'context' => ['status' => 'Active', 'sourceRef' => 'Verified fictional enrolment', 'dateOfBirth' => 'DO NOT STORE']])->assertRedirect()->assertSessionHasNoErrors();
        $o = ProgrammeOutcome::firstOrFail();
        $this->assertArrayNotHasKey('dateOfBirth', $o->context);
        $this->post('/apprentices/'.$a->id.'/outcome', ['version' => 0, 'context' => ['status' => 'Unknown']])->assertSessionHasErrors('version');
    }

    public function test_monthly_snapshot_preserves_not_observed_and_is_unique_per_calendar_month(): void
    {
        $u = $this->actor('tutor');
        $a = Apprentice::factory()->create(['tutor_id' => $u->id]);
        $month = today()->format('Y-m');
        $this->actingAs($u)->post('/apprentices/'.$a->id.'/monthly-snapshot', ['version' => 0, 'month' => $month, 'scores' => ['engagement' => 4], 'comment' => 'Engaged positively in observed practical work.', 'observed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $m = MonthlyScorecard::firstOrFail();
        $this->assertSame(4, $m->scores['engagement']);
        $this->assertNull($m->scores['safety']);
        $this->get('/apprentices/'.$a->id.'/monthly-snapshot')->assertOk()->assertSee('Engaged positively');
        $this->post('/apprentices/'.$a->id.'/monthly-snapshot', ['version' => 0, 'month' => $month, 'scores' => ['engagement' => 5], 'observed' => 1])->assertSessionHasErrors('version');
        $this->assertDatabaseCount('monthly_scorecards', 1);
    }

    public function test_low_or_unobserved_month_needs_comment_and_absence_is_not_zero(): void
    {
        $a = Apprentice::factory()->create();
        $this->actingAs($this->actor())->post('/apprentices/'.$a->id.'/monthly-snapshot', ['version' => 0, 'month' => today()->format('Y-m'), 'scores' => [], 'observed' => 1])->assertSessionHasErrors('comment');
        $this->post('/apprentices/'.$a->id.'/monthly-snapshot', ['version' => 0, 'month' => today()->format('Y-m'), 'scores' => [], 'comment' => 'No opportunity to observe this month.', 'observed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([null, null, null, null, null], array_values(MonthlyScorecard::firstOrFail()->scores));
    }

    public function test_apprentice_can_see_own_feedback_but_cannot_score_or_see_another(): void
    {
        $l = $this->actor('apprentice');
        $a = Apprentice::factory()->create(['user_id' => $l->id]);
        $other = Apprentice::factory()->create();
        MonthlyScorecard::factory()->create(['apprentice_id' => $a->id, 'comment' => 'Fictional constructive feedback']);
        $this->actingAs($l)->get('/apprentices/'.$a->id.'/monthly-snapshot')->assertOk()->assertSee('Fictional constructive feedback');
        $this->post('/apprentices/'.$a->id.'/monthly-snapshot', [])->assertForbidden();
        $this->get('/apprentices/'.$other->id.'/monthly-snapshot')->assertForbidden();
    }

    public function test_future_month_and_out_of_range_score_are_rejected(): void
    {
        $a = Apprentice::factory()->create();
        $this->actingAs($this->actor())->post('/apprentices/'.$a->id.'/monthly-snapshot', ['version' => 0, 'month' => today()->format('Y-m'), 'scores' => ['engagement' => 6], 'observed' => 1])->assertSessionHasErrors('scores.engagement');
        $this->post('/apprentices/'.$a->id.'/monthly-snapshot', ['version' => 0, 'month' => today()->addMonth()->format('Y-m'), 'scores' => ['engagement' => 4], 'observed' => 1])->assertSessionHasErrors('month');
    }

    public function test_saved_monthly_feedback_feeds_review_draft_without_duplicate_targets(): void
    {
        $a = Apprentice::factory()->create();
        MonthlyScorecard::factory()->create(['apprentice_id' => $a->id, 'month' => today()->format('Y-m'), 'comment' => 'Recorded tutor month feedback']);
        $this->actingAs($this->actor())->post('/apprentices/'.$a->id.'/reviews', ['fields' => ['reviewDate' => today()->toDateString(), 'summary' => 'Draft']])->assertRedirect()->assertSessionHasNoErrors();
        $r = ProgressReviewDraft::firstOrFail();
        $this->assertSame('Recorded tutor month feedback', $r->source_snapshot['monthly_snapshots'][0]['comment']);
        $this->get('/apprentices/'.$a->id.'/reviews/'.$r->id)->assertOk()->assertSee('Recorded tutor month feedback');
        $this->assertDatabaseCount('smart_actions', 0);
    }
}
