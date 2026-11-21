<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\ProgrammeOutcome;
use App\Models\TrainingSession;
use App\Models\TutorGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class RetentionRegisterAttendanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_report_uses_saved_registers_within_programme_dates_and_shows_marking_gaps(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 21));
        $admin = User::factory()->create(['role' => 'admin']);
        $group = TutorGroup::factory()->create();
        $newApprentice = fn () => Apprentice::factory()->create([
            'start_date' => '2026-09-01', 'planned_end_date' => '2027-09-01', 'tutor_group_id' => $group->id,
        ]);
        $early = $newApprentice();
        $ongoing = $newApprentice();
        $unverified = $newApprentice();
        foreach ([
            [$early, ['status' => 'Withdrawn', 'endDate' => '2026-10-15', 'sourceRef' => 'Test withdrawal']],
            [$ongoing, ['status' => 'Active', 'sourceRef' => 'Test enrolment']],
            [$unverified, ['status' => 'Withdrawn', 'sourceRef' => 'Test incomplete withdrawal']],
        ] as [$apprentice, $context]) {
            ProgrammeOutcome::create(['apprentice_id' => $apprentice->id, 'context' => $context]);
        }

        $register = function ($apprentice, $date, $code, bool $cancelled = false) use ($group, $admin): void {
            $session = TrainingSession::factory()->create([
                'tutor_group_id' => $group->id, 'created_by' => $admin->id,
                'session_date' => $date, 'members' => [$apprentice->id],
            ]);
            if ($cancelled) {
                $session->forceFill(['state' => 'Cancelled'])->save();
            }
            if ($code !== null) {
                $session->marks()->create([
                    'apprentice_id' => $apprentice->id, 'recorded_by' => $admin->id, 'code' => $code,
                    'comment' => 'Private register note',
                ]);
            }
        };
        $register($early, '2026-08-26', 'A');
        $register($early, '2026-09-02', 'O');
        $register($early, '2026-09-16', null, true);
        $register($early, '2026-09-30', 'B');
        $register($early, '2026-10-15', 'W');
        $register($early, '2026-10-21', null);
        $register($ongoing, '2026-10-21', 'T');
        $register($ongoing, '2026-11-11', null);
        $register($unverified, '2026-10-21', 'A');

        $response = $this->actingAs($admin)->get(route('retention.report'))->assertOk()
            ->assertSee('Register attendance by programme outcome')->assertSee('View apprentice registers')
            ->assertDontSee('Private register note');
        $byOutcome = $response->viewData('attendanceByOutcome');
        $this->assertEquals([
            'apprentices' => 1, 'known' => 1, 'mean' => 50,
            'attended' => 1, 'missed' => 1, 'unmarked' => 0,
        ], $byOutcome['Early leavers']);
        $this->assertNull($byOutcome['On programme']['mean']);
        $this->assertSame(1, $byOutcome['On programme']['unmarked']);
        $rows = $response->viewData('rows')->getCollection();
        $earlyAttendance = $rows->firstWhere('apprentice.id', $early->id)['recorded_attendance'];
        $this->assertSame('2026-10-15', $earlyAttendance['through']);
        $this->assertSame(1, $earlyAttendance['excluded']);
        $this->assertSame(1, $earlyAttendance['cancelled']);
        $this->assertNull($rows->firstWhere('apprentice.id', $unverified->id)['recorded_attendance']['through']);
    }
}
