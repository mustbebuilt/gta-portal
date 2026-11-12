<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\CareerDiscussion;
use App\Models\LearningTask;
use App\Models\Qualification;
use App\Models\SupportConcern;
use App\Models\User;
use App\Support\JourneyEvents;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DashboardJourneyTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role = 'admin'): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->save();

        return $u;
    }

    public function test_dashboard_with_missing_records_does_not_invent_overall_progress(): void
    {
        $this->actingAs($this->actor())->get('/dashboard')->assertOk()->assertSee('No assigned tasks in this scope.')->assertDontSee('100%');
        $this->get('/reports/operational')->assertOk()->assertSee('not the complete management pack');
        $this->get('/reports/inspection')->assertOk()->assertSee('not a complete Ofsted report');
    }

    public function test_tutor_and_apprentice_scope_stays_with_current_assignment(): void
    {
        $u = $this->actor('tutor');
        $l = $this->actor('apprentice');
        $a = Apprentice::factory()->create(['tutor_id' => $u->id, 'user_id' => $l->id]);
        $other = Apprentice::factory()->create();
        $this->actingAs($u)->get('/dashboard')->assertOk()->assertSee($a->reference)->assertDontSee($other->reference);
        $this->get('/dashboard?apprentice='.$other->id)->assertNotFound();
        $this->get('/apprentices/'.$other->id.'/journey')->assertForbidden();
        $this->actingAs($l)->get('/dashboard')->assertOk()->assertSee($a->reference)->assertDontSee($other->reference);
        $this->get('/reports/inspection')->assertForbidden();
    }

    public function test_dashboard_overdue_marking_uses_source_submission_date(): void
    {
        $this->travelTo(Carbon::parse('2026-11-11 18:00'));
        $a = Apprentice::factory()->create();
        $t = LearningTask::factory()->create(['apprentice_id' => $a->id]);
        $t->status = 'Awaiting marking';
        $t->submitted_at = now()->subDays(8);
        $t->save();
        $this->actingAs($this->actor())->get('/dashboard?apprentice='.$a->id)->assertOk()->assertViewHas('metrics', fn ($m) => $m['overdue_marking'] === 1 && $m['awaiting'] === 1);
        $this->get('/reports/operational')->assertOk()->assertSee('Overdue marking');
    }

    public function test_journey_uses_only_dated_recorded_public_learning_sources(): void
    {
        $a = Apprentice::factory()->create(['start_date' => '2026-01-01']);
        $q = Qualification::factory()->create(['apprentice_id' => $a->id, 'status' => 'Achieved', 'result' => 'Achieved', 'achievement_date' => '2026-10-01']);
        $c = CareerDiscussion::factory()->create(['apprentice_id' => $a->id, 'goals' => 'PRIVATE CAREER TEXT']);
        SupportConcern::factory()->create(['apprentice_id' => $a->id, 'initial_note' => 'PRIVATE SUPPORT TEXT']);
        $this->actingAs($this->actor())->get('/apprentices/'.$a->id.'/journey')->assertOk()->assertSee('road-art')->assertSee('Qualification result')->assertDontSee('PRIVATE CAREER TEXT')->assertDontSee('PRIVATE SUPPORT TEXT');
        $events = JourneyEvents::for($a);
        $this->assertCount(2, $events);
        $this->assertSame('2026-01-01', $events[0]['date']);
    }

    public function test_undated_and_future_milestones_are_not_invented(): void
    {
        $this->travelTo(Carbon::parse('2026-11-11'));
        $a = Apprentice::factory()->create(['start_date' => null]);
        Qualification::factory()->create(['apprentice_id' => $a->id, 'status' => 'Achieved', 'result' => 'Achieved', 'achievement_date' => null]);
        $this->assertSame([], JourneyEvents::for($a));
        $this->actingAs($this->actor())->get('/apprentices/'.$a->id.'/journey')->assertOk()->assertSee('No dated milestones recorded yet');
    }

    public function test_large_fictional_scope_does_not_leak_other_tutor_records(): void
    {
        $u = $this->actor('tutor');
        $mine = Apprentice::factory()->create(['reference' => 'VISIBLE-DEMO', 'tutor_id' => $u->id]);
        Apprentice::factory()->count(999)->create(['tutor_id' => null]);
        $this->actingAs($u)->get('/dashboard')->assertOk()->assertViewHas('metrics', fn ($m) => $m['apprentices'] === 1)->assertSee('VISIBLE-DEMO');
        $this->assertDatabaseCount('apprentices', 1000);
    }

    public function test_employer_and_unrelated_specialist_cannot_access_dashboard_or_journey(): void
    {
        $a = Apprentice::factory()->create();
        foreach (['employer', 'careers_adviser', 'functional_skills_tutor'] as $role) {
            $this->actingAs($this->actor($role))->get('/dashboard')->assertForbidden();
            $this->get('/apprentices/'.$a->id.'/journey')->assertForbidden();
        }
    }
}
