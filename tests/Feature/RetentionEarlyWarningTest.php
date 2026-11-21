<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\MonthlyScorecard;
use App\Models\ProgrammeOutcome;
use App\Models\TrainingSession;
use App\Models\TutorGroup;
use App\Models\User;
use App\Support\ApprenticeReviewData;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RetentionEarlyWarningTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_retention_compares_dated_evidence_and_only_flags_confirmed_active_apprentices(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 21));
        $admin = User::factory()->create(['role' => 'admin']);
        $group = TutorGroup::factory()->create();
        $make = function (string $reference, array $outcome): Apprentice {
            $apprentice = Apprentice::factory()->create([
                'reference' => $reference, 'start_date' => '2026-08-01', 'planned_end_date' => '2027-09-01',
            ]);
            if ($outcome !== []) {
                ProgrammeOutcome::create(['apprentice_id' => $apprentice->id, 'context' => $outcome + ['sourceRef' => 'Synthetic source']]);
            }

            return $apprentice;
        };
        $active = $make('LIVE-SIGNALS', ['status' => 'Active']);
        $missing = $make('LIVE-MISSING', ['status' => 'Active']);
        $paused = $make('ON-BREAK', ['status' => 'Break in learning']);
        $unknown = $make('STATUS-CHECK', []);
        $early = $make('LEFT-EARLY', ['status' => 'Withdrawn', 'endDate' => '2026-10-17', 'reason' => 'Employment ended']);
        $achieved = $make('ACHIEVED', [
            'status' => 'Completed', 'endDate' => '2026-11-12',
            'achievement' => 'Achieved', 'achievementDate' => '2026-11-13',
        ]);
        $session = function (Apprentice $apprentice, string $date, ?string $code) use ($admin, $group): TrainingSession {
            $session = TrainingSession::factory()->create([
                'created_by' => $admin->id, 'tutor_group_id' => $group->id,
                'session_date' => $date, 'members' => [$apprentice->id],
            ]);
            if ($code !== null) {
                $session->marks()->create(['apprentice_id' => $apprentice->id, 'code' => $code, 'recorded_by' => $admin->id]);
            }

            return $session;
        };
        $checkIn = function (Apprentice $apprentice, string $on, string $rating, ?string $revised = null) use ($admin, $session): void {
            $source = $session($apprentice, $on, 'O');
            $ratings = array_fill_keys(array_keys(ApprenticeReviewData::DEVELOPMENT_AREAS), 'Not observed');
            $ratings['application'] = $rating;
            $payload = ApprenticeReviewData::development($ratings, 'Private tutor observation') + [
                'at' => $on.'T17:00:00+00:00', 'session_id' => $source->id,
            ];
            $scorecard = new MonthlyScorecard([
                'apprentice_id' => $apprentice->id, 'training_session_id' => $source->id, 'tutor_id' => $admin->id,
                'month' => substr($on, 0, 7), 'scores' => [],
            ]);
            if ($revised) {
                $scorecard->history = [['before_development' => $payload]];
                $payload['at'] = $revised.'T17:00:00+00:00';
                $payload['ratings']['application'] = 'Consistently demonstrated';
            }
            $scorecard->setAttribute('development', json_encode($payload, JSON_THROW_ON_ERROR));
            $scorecard->save();
        };
        $checkIn($active, '2026-09-30', 'Needs support');
        $checkIn($active, '2026-10-21', 'Needs support');
        $session($active, '2026-11-04', 'A');
        $session($active, '2026-11-11', 'W');
        $session($missing, '2026-11-04', null);
        $checkIn($early, '2026-10-07', 'Needs support', '2026-10-20');
        $checkIn($achieved, '2026-11-04', 'Consistently demonstrated');
        foreach ([$active, $paused, $unknown] as $apprentice) {
            DB::table('apprentice_markers')->insert([
                'apprentice_id' => $apprentice->id, 'code' => 'IM34', 'recorded_by' => $admin->id,
                'created_at' => '2026-11-15', 'updated_at' => '2026-11-15',
            ]);
        }

        $response = $this->actingAs($admin)->get(route('retention.report'))->assertOk()
            ->assertSee('Evidence before early leaving and achievement')
            ->assertSee('Application and effort')->assertSee('Non-attendance across two weeks')
            ->assertSee('Employment ended')->assertDontSee('Private tutor observation');
        $report = $response->viewData('earlyWarning');
        $this->assertSame(2, $report['active']);
        $this->assertSame(1, $report['active_with_check_ins']);
        $this->assertSame(1, $report['status_checks']);
        $this->assertSame(['LIVE-SIGNALS'], $report['warnings']->pluck('apprentice.reference')->values()->all());
        $evidence = $report['warnings']->first()['early_warning'];
        $this->assertSame(3, $evidence['signal_count']);
        $this->assertSame(2, $evidence['needs_support']['application']['support_months']);
        $this->assertSame(1, $report['outcomes']['Early leavers']['areas']['application']['needs_support']);
        $this->assertSame(1, $report['outcomes']['Achievers']['areas']['application']['consistent']);
        $this->assertSame(0, $report['outcomes']['Early leavers']['areas']['safety']['observed']);

        $session($active, '2026-11-18', 'O');
        $next = $this->get(route('retention.report'))->assertOk()->viewData('earlyWarning');
        $this->assertNull($next['warnings']->first()['early_warning']['attendance']);

        $this->actingAs(User::factory()->create(['role' => 'tutor']))
            ->get(route('retention.report'))->assertForbidden();
    }
}
