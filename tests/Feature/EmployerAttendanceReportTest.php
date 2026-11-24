<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\ApprenticeEntryContext;
use App\Models\Employer;
use App\Models\EntryContextEvent;
use App\Models\ProgrammeOutcome;
use App\Models\SupportCapability;
use App\Models\TrainingSession;
use App\Models\TutorGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployerAttendanceReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function entry(User $admin, Apprentice $apprentice, Employer $employer): void
    {
        ApprenticeEntryContext::create([
            'apprentice_id' => $apprentice->id, 'updated_by' => $admin->id, 'stage' => 'Enrolment', 'entry_employer_id' => $employer->id,
            'employment_start_date' => '2026-09-01', 'confirmed_on' => '2026-09-01',
        ]);
    }

    private function register(User $admin, TutorGroup $group, string $date, array $marks, bool $cancelled = false): TrainingSession
    {
        $session = TrainingSession::factory()->create([
            'created_by' => $admin->id, 'tutor_group_id' => $group->id, 'session_date' => $date,
            'members' => array_keys($marks),
        ]);
        if ($cancelled) {
            $session->forceFill(['state' => 'Cancelled'])->save();
        }
        foreach ($marks as $id => $code) {
            $session->marks()->create([
                'apprentice_id' => $id, 'recorded_by' => $admin->id, 'code' => $code,
                'comment' => 'Private register note',
            ]);
        }
        return $session;
    }

    public function test_counts_distinct_apprentices_and_uses_the_dated_employer_for_each_register(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 25));
        $admin = User::factory()->create(['role' => 'admin']);
        $group = TutorGroup::factory()->create();
        $before = Employer::factory()->create(['name' => 'Same Workshop', 'reference' => 'EMP-FIRST']);
        $after = Employer::factory()->create(['name' => 'Same Workshop', 'reference' => 'EMP-SECOND']);
        $moved = Apprentice::factory()->create(['start_date' => '2026-09-01', 'employer_id' => $after->id]);
        $stayed = Apprentice::factory()->create(['start_date' => '2026-09-01', 'employer_id' => $before->id]);
        $unknown = Apprentice::factory()->create(['start_date' => '2026-09-01', 'employer_id' => $after->id]);
        $this->entry($admin, $moved, $before);
        $this->entry($admin, $stayed, $before);

        EntryContextEvent::create([
            'apprentice_id' => $moved->id, 'actor_id' => $admin->id, 'kind' => 'Employment', 'effective_on' => '2026-10-20',
            'revision' => 1, 'payload' => ['after' => ['entry_employer_id' => $after->id], 'reason' => 'Private reason'],
        ]);
        DB::table('assignment_change_events')->insert([
            'apprentice_id' => $moved->id, 'actor_id' => $admin->id, 'created_at' => '2026-10-19 10:00:00',
            'before_links' => json_encode(['employer_id' => $before->id]), 'after_links' => json_encode(['employer_id' => $after->id]),
        ]);
        ProgrammeOutcome::create(['apprentice_id' => $stayed->id, 'context' => [
            'status' => 'Withdrawn', 'endDate' => '2026-10-22', 'sourceRef' => 'Test completed withdrawal',
        ]]);
        $shared = $this->register($admin, $group, '2026-10-12', [$moved->id => 'W', $stayed->id => 'W']);
        $this->register($admin, $group, '2026-10-19', [$moved->id => 'W', $stayed->id => 'S', $unknown->id => 'W']);
        $this->register($admin, $group, '2026-10-20', [$moved->id => 'W']);
        $this->register($admin, $group, '2026-10-23', [$stayed->id => 'W']);
        $this->register($admin, $group, '2026-10-27', [$moved->id => 'W'], true);
        $this->register($admin, $group, '2026-09-28', [$moved->id => 'W']);

        $date = ['from' => '2026-10-01', 'to' => '2026-10-31'];
        $url = fn (array $filter = []) => route('retention.employer-attendance', $date + $filter);
        $response = $this->actingAs($admin)->get($url())->assertOk()->assertSee('Employer needs checking')
            ->assertSee('EMP-FIRST')->assertSee('EMP-SECOND')->assertDontSee('Private reason')->assertDontSee('Private register note');
        $groups = $response->viewData('groups');
        $this->assertSame(2, $groups[$before->id]['apprentices']);
        $this->assertSame(3, $groups[$before->id]['sessions']);
        $this->assertSame(1, $groups[$after->id]['sessions']);
        $this->assertSame(1, $groups['unknown']['sessions']);
        $first = $this->get($url(['employer' => $before->id]))->assertOk()->assertSee(route('attendance.show', $shared));
        $this->assertSame(3, $first->viewData('details')->total());
        $this->assertSame(1, $this->get($url(['employer' => 'unknown']))->assertOk()->viewData('details')->total());
        $this->get(route('retention.report'))->assertOk()->assertSee('View Kept at work by employer');
        $this->actingAs(User::factory()->create(['role' => 'tutor']))->get($url())->assertForbidden();
    }

    public function test_undated_link_change_does_not_assign_the_missed_session_to_either_employer(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 25));
        $admin = User::factory()->create(['role' => 'admin']);
        $group = TutorGroup::factory()->create();
        $original = Employer::factory()->create();
        $current = Employer::factory()->create();
        $apprentice = Apprentice::factory()->create(['start_date' => '2026-09-01', 'employer_id' => $current->id]);
        $this->entry($admin, $apprentice, $original);
        $this->register($admin, $group, '2026-11-10', [$apprentice->id => 'W']);
        $response = $this->actingAs($admin)->get(route('retention.employer-attendance'))->assertOk();
        $this->assertSame(1, $response->viewData('groups')->sole()['sessions']);
        $this->assertSame('unknown', $response->viewData('details')->items()[0]['employer']);
    }
    public function test_meeting_lists_two_distinct_scoped_apprentices_from_four_completed_weeks(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 17:00:00', 'Europe/London'));
        $admin = User::factory()->create(['role' => 'admin']);
        $support = User::factory()->create(['role' => 'tutor']);
        $group = TutorGroup::factory()->create();
        $employer = Employer::factory()->create(['name' => 'Fictional Meeting Workshop', 'reference' => 'EMP-MEETING']);
        $first = Apprentice::factory()->create(['start_date' => '2026-09-01', 'employer_id' => $employer->id]);
        $second = Apprentice::factory()->create(['start_date' => '2026-09-01', 'employer_id' => $employer->id]);
        $unverified = Apprentice::factory()->create(['start_date' => '2026-09-01', 'employer_id' => $employer->id]);
        $this->entry($admin, $first, $employer);
        $this->entry($admin, $second, $employer);
        $capability = SupportCapability::create([
            'user_id' => $support->id, 'duty' => 'member', 'scope' => 'assigned',
            'apprentice_ids' => [$first->id], 'group_ids' => [],
        ]);

        $this->register($admin, $group, '2026-10-25', [$second->id => 'W']);
        $this->register($admin, $group, '2026-10-26', [$second->id => 'W']);
        $this->register($admin, $group, '2026-11-15', [$first->id => 'W']);
        $this->register($admin, $group, '2026-11-22', [$first->id => 'W', $unverified->id => 'W']);
        $this->register($admin, $group, '2026-11-23', [$second->id => 'W']);
        $meeting = route('support.worklist', ['view' => 'needs-review']);

        $this->actingAs($support)->get($meeting)->assertOk()->assertSee('Employers to review')
            ->assertDontSee('Fictional Meeting Workshop');
        $capability->update(['apprentice_ids' => [$first->id, $second->id, $unverified->id]]);
        $this->get($meeting)->assertOk()->assertSee('Fictional Meeting Workshop')
            ->assertSee('2 apprentices · 3 missed apprentice sessions')
            ->assertSee(route('support.index', $second), false)
            ->assertDontSee(route('support.index', $unverified), false)
            ->assertDontSee('Open source registers');
        $this->actingAs($admin)->get($meeting)->assertOk()->assertSee('Open source registers')
            ->assertSee('2026-10-26 to 2026-11-22');
        $this->get(route('support.worklist', ['view' => 'all']))->assertOk()->assertDontSee('Employers to review');

        // On the next Monday the first miss has aged out and the 23rd is now within scope.
        $this->travel(7)->days();
        $this->get($meeting)->assertOk()->assertSee('Fictional Meeting Workshop');
        $this->travel(21)->days();
        $this->get($meeting)->assertOk()->assertDontSee('Fictional Meeting Workshop');
    }
}
