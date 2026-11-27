<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\AttendanceMark;
use App\Models\LearningTask;
use App\Models\TrainingSession;
use App\Models\TutorGroup;
use App\Models\User;
use App\Support\ReportDirectory;
use App\Support\ReportThresholds;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ReportPresentationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_all_report_links_open_and_lead_with_a_chart_and_its_exact_values(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $directory = ReportDirectory::links($admin);
        $this->assertCount(12, $directory);
        $dashboard = $this->get(route('reports.index'))->assertOk()->assertSee('Headline figures');
        foreach ($directory as $name => $label) {
            $dashboard->assertSee(route($name), false);
            $report = $this->get(route($name))->assertOk();
            if ($name === 'visual-reports.index') {
                $report->assertSeeInOrder(['Weekly apprentice-session attendance', 'exact values', 'Apprentice records']);
            } else {
                $report->assertSeeInOrder(['class="report-bars"', '<caption>Exact figures</caption>'], false);
            }
        }
    }

    public function test_internal_rating_boundaries_use_the_unrounded_value_and_hold_small_samples(): void
    {
        foreach ([[90, 10, 'green'], [89.99, 10, 'amber'], [85, 10, 'amber'], [84.99, 10, 'red'], [100, 9, 'grey'], [null, 12, 'grey']] as [$rate, $count, $expected]) {
            $this->assertSame($expected, ReportThresholds::internal($rate, 'attendance', $count)['status']);
        }
        foreach ([[0, 'green'], [1, 'amber'], [4, 'amber'], [5, 'red'], [null, 'grey']] as [$count, $expected]) {
            $this->assertSame($expected, ReportThresholds::internal($count, 'exceptions')['status']);
        }
        $this->assertSame('grey', ReportThresholds::internal(100, null)['status']);
    }

    public function test_tutor_dashboard_rates_only_its_authorised_attendance_and_marking(): void
    {
        $tutor = User::factory()->create(['role' => 'tutor']);
        $own = Apprentice::factory()->create(['tutor_id' => $tutor->id]);
        $other = Apprentice::factory()->create();
        $date = now('Europe/London')->startOfWeek()->subWeek()->toDateString();
        $sessions = TrainingSession::factory()->count(10)->create(['session_date' => $date, 'members' => [$own->id, $other->id]]);
        foreach ($sessions as $i => $session) {
            foreach ([$own, $other] as $person) {
                AttendanceMark::create(['training_session_id' => $session->id, 'apprentice_id' => $person->id,
                    'recorded_by' => $tutor->id, 'code' => $person->is($own) && $i < 9 ? 'O' : 'A']);
            }
        }
        LearningTask::factory()->create(['apprentice_id' => $other->id, 'status' => 'Awaiting marking', 'submitted_at' => now()->subDays(20)]);
        $this->actingAs($tutor)->get(route('reports.index'))->assertOk()->assertViewHas('headlines', function ($headlines) {
            $items = collect($headlines)->keyBy('name');
            return $items['Attendance']['value'] === 90.0 && $items['Attendance']['status'] === 'green'
                && $items['Overdue marking']['value'] === 0.0;
        })->assertDontSee('Apprenticeship Accountability Framework thresholds');
    }

    public function test_damaged_register_keeps_reports_open_and_holds_attendance_in_html_and_pdf(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $person = Apprentice::factory()->create();
        $badDate = now('Europe/London')->startOfWeek()->subWeek()->toDateString();
        TrainingSession::factory()->create(['session_date' => $badDate, 'members' => [$person->id, $person->id]]);
        $this->actingAs($admin)->get(route('reports.index'))->assertOk()->assertSee('Saved register rosters need checking');
        $this->get(route('visual-reports.index'))->assertOk()->assertSee('Attendance figures are unavailable')
            ->assertSee($person->reference)->assertViewHas('report', fn ($report) =>
                $report['attendance_blocked'] && $report['attendance_total'] === null
                && collect($report['attendance_weekly'])->every(fn ($row) => $row['value'] === null && $row['expected'] === null));
        $this->get(route('visual-reports.pdf'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $outside = today()->subWeeks(12)->toDateString();
        $this->get(route('visual-reports.index', ['from' => $outside, 'to' => $outside]))->assertOk()
            ->assertViewHas('report', fn ($report) => !$report['attendance_blocked']);
    }

    public function test_visual_report_recovers_from_large_scope_and_reopens_with_a_group(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $group = TutorGroup::factory()->create(['name' => 'Fictional report group']);
        Apprentice::factory()->count(200)->create();
        Apprentice::factory()->create(['tutor_group_id' => $group->id]);
        $this->actingAs($admin)->get(route('visual-reports.index'))->assertUnprocessable()
            ->assertSee('Change the report selection')->assertSee('more than 200 records')
            ->assertSee('Fictional report group');
        $person = Apprentice::where('tutor_group_id', $group->id)->sole();
        $this->get(route('visual-reports.index', ['reference' => $person->reference]))->assertOk()
            ->assertViewHas('report', fn ($report) => $report['filters']['apprentice'] === $person->id);
        $this->get(route('visual-reports.index', ['group' => $group->id]))->assertOk()
            ->assertViewHas('report', fn ($report) => $report['record_count'] === 1);
        $this->get(route('visual-reports.index', ['from' => today()->toDateString(), 'to' => today()->subDay()->toDateString()]))
            ->assertUnprocessable()->assertSee('Open with default dates');
    }
}
