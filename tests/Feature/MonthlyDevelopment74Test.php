<?php

namespace Tests\Feature;

use App\Models\{Apprentice, MonthlyScorecard, TrainingSession, User};
use App\Support\{ApprenticeReviewData as D, MonthlyDevelopment};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class MonthlyDevelopment74Test extends TestCase
{
    use LazilyRefreshDatabase;

    private function setupCase(): array
    {
        $u = User::factory()->create(['role' => 'tutor', 'active' => true]);
        $a = Apprentice::factory()->create(['tutor_id' => $u->id, 'start_date' => '2026-01-01']);
        $s = TrainingSession::factory()->create(['session_date' => today()->subDay()->toDateString(), 'members' => [$a->id]]);
        $s->marks()->create(['apprentice_id' => $a->id, 'code' => 'O', 'recorded_by' => $u->id]);

        return [$u, $a, $s, array_fill_keys(array_keys(D::DEVELOPMENT_AREAS), 'Developing')];
    }

    public function test_new_snapshot_reuses_one_calendar_month_row(): void
    {
        [$u, $a, $s, $v] = $this->setupCase();
        $r = MonthlyDevelopment::save($u, $a, $s, 0, $v, 'Observed practical work.');
        $this->assertSame('gta-development-v2', MonthlyDevelopment::read($r)['schema']);
        $this->assertSame([], $r->scores);
        $again = MonthlyDevelopment::save($u, $a, $s, $r->version, $v, 'Observed practical work.');
        $this->assertSame($r->version, $again->version);
        $this->assertDatabaseCount('monthly_scorecards', 1);
        $this->assertCount(1, $again->history);
    }

    public function test_legacy_numeric_scores_and_comment_are_not_converted_or_overwritten(): void
    {
        [$u, $a, $s, $v] = $this->setupCase();
        $old = MonthlyScorecard::create(['apprentice_id' => $a->id, 'month' => $s->session_date->format('Y-m'), 'tutor_id' => $u->id, 'scores' => ['engagement' => 4], 'comment' => 'Legacy observation']);
        $r = MonthlyDevelopment::save($u, $a, $s, $old->version, $v, 'New scale observation');
        $this->assertSame(4, $r->scores['engagement']);
        $this->assertSame('Legacy observation', $r->comment);
        $this->assertSame('New scale observation', MonthlyDevelopment::read($r)['comment']);
    }

    public function test_stale_version_is_rejected(): void
    {
        [$u, $a, $s, $v] = $this->setupCase();
        MonthlyDevelopment::save($u, $a, $s, 0, $v, '');
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        MonthlyDevelopment::save($u, $a, $s, 0, $v, '');
    }

    public function test_absent_apprentice_is_not_forced_to_receive_a_score(): void
    {
        [$u, $a, $s, $v] = $this->setupCase();
        $s->marks()->update(['code' => 'A']);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        MonthlyDevelopment::save($u, $a, $s, 0, $v, '');
    }

    public function test_unassigned_tutor_cannot_save(): void
    {
        [$u, $a, $s, $v] = $this->setupCase();
        $other = User::factory()->create(['role' => 'tutor', 'active' => true]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        MonthlyDevelopment::save($other, $a, $s, 0, $v, '');
    }

    public function test_update_preserves_prior_development_evidence(): void
    {
        [$u, $a, $s, $v] = $this->setupCase();
        $r = MonthlyDevelopment::save($u, $a, $s, 0, $v, 'First');
        $v['application'] = 'Consistently demonstrated';
        $r = MonthlyDevelopment::save($u, $a, $s, $r->version, $v, 'Updated');
        $this->assertSame('First', $r->history[1]['before_development']['comment']);
        $this->assertSame('Updated', MonthlyDevelopment::read($r)['comment']);
    }
}
