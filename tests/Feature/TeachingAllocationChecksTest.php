<?php

namespace Tests\Feature;

use App\Models\{Apprentice, Employer, Programme, TimetablePlan, TimetableRoom, TrainingSession, TutorGroup, User};
use App\Support\{CentrePlanning, ReviewedImports};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeachingAllocationChecksTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function lesson(TutorGroup $group, string $date, array $members, array $overrides = []): TrainingSession
    {
        return TrainingSession::factory()->create([
            'tutor_group_id' => $group->id, 'session_date' => $date, 'members' => $members,
            'start_time' => '09:00', 'end_time' => '12:00', ...$overrides,
        ]);
    }

    public function test_register_creation_blocks_same_employer_on_the_same_day_even_at_another_time(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $employer = Employer::factory()->create();
        [$firstGroup, $secondGroup] = TutorGroup::factory()->count(2)->create();
        $first = Apprentice::factory()->create(['employer_id' => $employer->id, 'tutor_group_id' => $firstGroup->id]);
        $second = Apprentice::factory()->create(['employer_id' => $employer->id, 'tutor_group_id' => $secondGroup->id]);
        $date = today()->addWeek()->toDateString();
        // The second week is the only clash. The first occurrence must roll back with it.
        $clash = $this->lesson($firstGroup, today()->addWeeks(2)->toDateString(), [$first->id]);
        $params = [
            'tutor_group_id' => $secondGroup->id, 'name' => 'Workshop', 'session_date' => $date,
            'start_time' => '13:00', 'end_time' => '16:00', 'planned_learning' => 'Workshop practice',
            'repeat_weeks' => 2, 'breaks_checked' => 1,
        ];

        $this->actingAs($admin)->post(route('attendance.create'), $params)
            ->assertSessionHasErrors(['session_date' => 'Employer release clash on '.$clash->session_date->toDateString()
                .' for '.$employer->reference.'. Apprentices at the same employer need different teaching days.']);
        $this->assertDatabaseCount('training_sessions', 1);
        $this->post(route('attendance.create'), [...$params, 'session_date' => today()->addWeek()->addDay()->toDateString()])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('training_sessions', 3);
        $this->assertSame([$second->id], TrainingSession::where('tutor_group_id', $secondGroup->id)->first()->members);
    }

    public function test_weekly_schedule_checks_an_unopened_weekly_register(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $employer = Employer::factory()->create();
        [$first, $second] = TutorGroup::factory()->count(2)->create();
        foreach ([$first, $second] as $group) {
            Apprentice::factory()->create(['employer_id' => $employer->id, 'tutor_group_id' => $group->id]);
        }
        $data = [
            'record_version' => 1, 'first_day' => today()->addWeek()->toDateString(), 'start' => '09:00',
            'end' => '16:00', 'planned_learning' => 'Weekly workshop', 'breaks_checked' => 1,
        ];
        $this->actingAs($admin)->post(route('attendance.weekly.configure', $first), $data)->assertSessionHasNoErrors();
        $this->post(route('attendance.weekly.configure', $second), $data)->assertSessionHasErrors('first_day');
        $this->assertNull($second->refresh()->weekly_register);
        $this->assertDatabaseCount('training_sessions', 0);
    }

    public function test_timetable_draft_flags_release_clash_before_check_and_rechecks_at_publish(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $employer = Employer::factory()->create();
        $tutor = User::factory()->create(['role' => 'tutor']);
        $group = TutorGroup::factory()->create(['tutor_id' => $tutor->id]);
        $otherGroup = TutorGroup::factory()->create();
        Apprentice::factory()->create(['employer_id' => $employer->id, 'tutor_group_id' => $group->id]);
        $other = Apprentice::factory()->create(['employer_id' => $employer->id, 'tutor_group_id' => $otherGroup->id]);
        $date = today()->addWeek()->toDateString();
        $room = TimetableRoom::where('site', 'Doncaster')->firstOrFail();
        CentrePlanning::room($admin, 1, $room, $room->version, ['capacity' => 10, 'uses' => ['Teaching'], 'active' => true]);
        CentrePlanning::windows($admin, 1, $tutor, [$date], ReviewedImports::hash(CentrePlanning::windowSource($tutor, [$date])), true, '08:00', '18:00');
        $this->actingAs($admin)->post(route('centre-planning.save'), [
            'request_token' => (string) Str::uuid(), 'version' => 0, 'staff_id' => $tutor->id,
            'date' => $date, 'weeks' => 1, 'start' => '13:00', 'end' => '16:00', 'kind' => 'Teaching',
            'site' => 'Doncaster', 'room_id' => $room->id, 'group_id' => $group->id, 'headcount' => 1, 'confirmed' => 1,
        ])->assertSessionHasNoErrors();
        $draft = TimetablePlan::sole();
        CentrePlanning::check($admin, 1, $draft, 1, true);
        $otherGroup->update(['tutor_id' => User::factory()->create(['role' => 'tutor'])->id]);
        $this->lesson($otherGroup, $date, [$other->id]);
        $this->get(route('centre-planning.show', $draft))->assertOk()->assertSee('Employer release clash on '.$date);
        try {
            CentrePlanning::publish($admin, 1, $draft->refresh(), 2);
            $this->fail('Publication should have been held');
        } catch (\Illuminate\Validation\ValidationException $error) {
            $this->assertArrayHasKey('planning', $error->errors());
        }
        $this->assertSame('Draft', $draft->refresh()->state);
    }

    public function test_group_assignment_blocks_different_training_year_and_same_employer_inside_the_class(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $employer = Employer::factory()->create();
        $programme = Programme::factory()->create();
        $yearOne = TutorGroup::factory()->create(['name' => 'Autocare Year 1', 'programme_id' => $programme->id]);
        $yearTwo = TutorGroup::factory()->create(['name' => 'Autocare Year 2', 'programme_id' => $programme->id]);
        $start = today()->subMonths(2)->toDateString();
        $member = Apprentice::factory()->create([
            'tutor_group_id' => $yearOne->id, 'programme_id' => $programme->id, 'start_date' => $start,
            'employer_id' => $employer->id,
        ]);
        $candidate = Apprentice::factory()->create([
            'programme_id' => $programme->id, 'start_date' => $start, 'employer_id' => $employer->id,
        ]);
        $date = today()->addWeek()->toDateString();
        $this->lesson($yearOne, $date, [$member->id]);
        $input = [...$candidate->only(['reference', 'first_name', 'last_name', 'employer_id', 'programme_id', 'version']), 'start_date' => $start];
        $route = route('apprentices.update', $candidate);
        $this->actingAs($admin)->put($route, [...$input, 'tutor_group_id' => $yearTwo->id])
            ->assertSessionHasErrors(['tutor_group_id' => 'Training position mismatch. This apprentice is in Year 1 on '
                .today()->toDateString().'. Choose a class at the same training stage.']);
        $this->put($route, [...$input, 'tutor_group_id' => $yearOne->id])->assertSessionHasErrors([
            'tutor_group_id' => 'Employer release clash on '.$date.' for '.$employer->reference
                .'. Apprentices at the same employer need different teaching days.',
        ]);
        $this->assertNull($candidate->refresh()->tutor_group_id);

        $candidate->update(['employer_id' => Employer::factory()->create()->id]);
        $this->put($route, [...$input, 'employer_id' => $candidate->employer_id, 'tutor_group_id' => $yearOne->id])
            ->assertSessionHasNoErrors();
        $this->assertEquals($yearOne->id, $candidate->refresh()->tutor_group_id);
    }
}
