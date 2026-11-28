<?php

namespace Tests\Feature;

use App\Models\{Apprentice, AttendanceMark, ProgrammeOutcome, TrainingSession, User};
use App\Support\ReportComparisons;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ReportComparisonsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_attendance_uses_weighted_counts_and_the_same_permission_scope(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 27));
        $tutor = User::factory()->create(['role' => 'tutor']);
        $first = Apprentice::factory()->create(['tutor_id' => $tutor->id, 'programme' => 'Autocare']);
        $second = Apprentice::factory()->create(['tutor_id' => $tutor->id, 'programme' => 'Autocare']);
        $outside = Apprentice::factory()->create(['programme' => 'Outside tutor scope']);
        foreach ([[$first, 'O', '2026-11-21'], [$first, 'O', '2026-11-22'], [$second, 'A', '2026-11-22'], [$outside, 'O', '2026-11-22']] as [$person, $code, $date]) {
            $session = TrainingSession::factory()->create(['session_date' => $date, 'members' => [$person->id]]);
            AttendanceMark::create(['training_session_id' => $session->id, 'apprentice_id' => $person->id, 'recorded_by' => $tutor->id, 'code' => $code, 'otj_confirmed' => false]);
        }

        $this->actingAs($tutor)->get('/visual-reports?from=2026-11-21&to=2026-11-23')
            ->assertOk()->assertSee('Progress reviews due')->assertSee('Off-the-job hours against target to date')
            ->assertSee('Attendance by programme')->assertDontSee('Outside tutor scope')
            ->assertViewHas('report', function ($report) {
                $row = collect($report['charts'])->firstWhere('key', 'attendance-by-programme')['rows'][0];
                $this->assertSame(['label' => 'Autocare', 'value' => 66.7, 'attended' => 2, 'expected' => 3, 'unmarked' => 0], $row);

                return true;
            });
        $this->get('/visual-reports.pdf?from=2026-11-21&to=2026-11-23')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_reviews_and_otj_distinguish_unknown_sources_and_use_known_pairs(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 27));
        $base = ['programme' => 'Autocare', 'attendance' => null, 'recorded_hours' => 0.0, 'progressive_expected_hours' => 10.0, 'otj_pending' => 0, 'otj_checks' => 0];
        $details = collect([
            [...$base, 'id' => 1, 'review_due' => '2026-11-26'],
            [...$base, 'id' => 2, 'review_due' => '2026-12-27', 'recorded_hours' => 12.25, 'progressive_expected_hours' => 15.5],
            [...$base, 'id' => 3, 'review_due' => '2026-12-28', 'otj_checks' => 1],
            [...$base, 'id' => 4, 'review_due' => null, 'progressive_expected_hours' => null],
            [...$base, 'id' => 5, 'review_due' => '2026-11-26', 'otj_pending' => 1],
        ]);
        $active = new ProgrammeOutcome(['context' => ['status' => 'Active', 'sourceRef' => 'Verified record']]);
        $closed = new ProgrammeOutcome(['context' => ['status' => 'Completed', 'sourceRef' => 'Verified record']]);
        [$reviews, $attendance, $otj] = ReportComparisons::charts($details, collect([1 => $active, 2 => $active, 3 => $active, 5 => $closed]));
        $this->assertSame([1, 1, 1, 1], array_column($reviews['rows'], 'value'));
        $this->assertNull($attendance['rows'][0]['value']);
        $this->assertSame([12.25, 25.5], array_column($otj['rows'], 'value'));
        $this->assertStringContainsString('2 of 5 apprentices', $otj['note']);
    }

    public function test_reports_and_progress_use_the_shared_theme_asset(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['reports.index', 'visual-reports.index', 'progress66.index', 'cohort-insights.index', 'support.report'] as $route) {
            $this->get(route($route))->assertOk()->assertSee('report-measures118.css')->assertSee('data-portal-theme="117"', false);
        }
    }
}
