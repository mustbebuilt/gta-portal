<?php

namespace Tests\Feature;

use App\Models\StaffDevelopmentProfile as Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class StaffImpactScheduleTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->save();

        return $u;
    }

    private function fixture(string $kind = 'cpd', string $status = 'In progress', bool $shared = false): array
    {
        $u = $this->actor('tutor');
        $manager = $this->actor('manager');
        $ceo = $this->actor('ceo');
        $this->actingAs($ceo)->post('/staff-development/'.$u->id.'/manager', ['version' => 0, 'manager_id' => $manager->id, 'reason' => 'Fictional assignment'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($manager)->post('/staff-development/'.$u->id.'/entry', ['version' => 1, 'kind' => $kind, 'status' => $status, 'date' => today()->toDateString(), 'title' => 'Fictional technical development', 'summary' => 'Fictional diagnosis practice', 'shared' => $shared, 'evidence' => 'Fictional source reference'])->assertRedirect()->assertSessionHasNoErrors();
        $p = Profile::where('user_id', $u->id)->firstOrFail();

        return [$u, $manager, $p->payload['entries'][0]];
    }

    private function values(array $entry, array $changes = []): array
    {
        return [...['version' => 2, 'entry_id' => $entry['id'], 'source_revision' => $entry['revision'], 'date' => today()->toDateString(), 'baseline_status' => 'recorded', 'baseline' => 'Original observation showed an explanation gap', 'outcome' => 'improved', 'basis' => 'observation', 'summary' => 'Clearer diagnostic explanation observed', 'evidence' => 'Fictional follow-up observation reference', 'followup' => 'none'], ...$changes];
    }

    public function test_manager_records_separate_impact_with_frozen_source_and_private_notes(): void
    {
        [$u,$m,$entry] = $this->fixture();
        $url = '/staff-development/'.$u->id;
        $this->actingAs($m)->post($url.'/impact', $this->values($entry, ['shared' => 1, 'private_notes' => 'Private management assessment']))->assertRedirect()->assertSessionHasNoErrors();
        $p = Profile::where('user_id', $u->id)->firstOrFail();
        $this->assertSame(1, $p->payload['impacts'][0]['source']['revision']);
        $this->assertSame([], $p->payload['actions']);
        $this->actingAs($u)->get($url)->assertOk()->assertSee('Clearer diagnostic explanation observed')->assertDontSee('Private management assessment');
        $this->actingAs($m)->get($url.'?view=manager')->assertOk()->assertSee('Private management assessment')->assertSee('Review this development');
    }

    public function test_employee_and_unassigned_admin_cannot_record_staff_impact(): void
    {
        [$u,$m,$e] = $this->fixture();
        $url = '/staff-development/'.$u->id.'/impact';
        $this->actingAs($u)->post($url, $this->values($e))->assertForbidden();
        $this->actingAs($this->actor('admin'))->post($url, $this->values($e))->assertForbidden();
        $this->assertSame([], Profile::where('user_id', $u->id)->firstOrFail()->payload['impacts']);
    }

    public function test_impact_cannot_claim_improvement_from_missing_baseline_unchecked_or_unstarted_cpd(): void
    {
        [$u,$m,$e] = $this->fixture('cpd', 'Planned');
        $url = '/staff-development/'.$u->id.'/impact';
        $this->actingAs($m)->post($url, $this->values($e))->assertSessionHasErrors('outcome');
        $this->post($url, $this->values($e, ['baseline_status' => 'missing']))->assertSessionHasErrors('outcome');
        $this->post($url, $this->values($e, ['basis' => 'unchecked']))->assertSessionHasErrors('outcome');
        $this->post($url, $this->values($e, ['outcome' => 'too_early', 'baseline_status' => 'missing', 'basis' => 'unchecked']))->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_impact_respects_source_revision_chronology_and_future_followup(): void
    {
        [$u,$m,$e] = $this->fixture();
        $url = '/staff-development/'.$u->id.'/impact';
        $this->actingAs($m)->post($url, $this->values($e, ['source_revision' => 99]))->assertSessionHasErrors('entry_id');
        $this->post($url, $this->values($e, ['date' => today()->subDay()->toDateString()]))->assertSessionHasErrors('date');
        $this->post($url, $this->values($e, ['followup' => 'review', 'next_date' => today()->toDateString()]))->assertSessionHasErrors('next_date');
        $this->post($url, $this->values($e, ['followup' => 'review', 'next_date' => today()->addDays(14)->toDateString()]))->assertRedirect()->assertSessionHasNoErrors();
        $this->post($url, $this->values($e))->assertSessionHasErrors('version');
    }

    public function test_private_observation_cannot_be_exposed_by_shared_impact_and_brief_note_is_not_full_observation(): void
    {
        [$u,$m,$e] = $this->fixture('observation');
        $this->actingAs($m)->post('/staff-development/'.$u->id.'/impact', $this->values($e, ['shared' => 1]))->assertSessionHasErrors('shared');
        $this->post('/staff-development/'.$u->id.'/impact', $this->values($e))->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($u)->get('/staff-development/'.$u->id)->assertOk()->assertDontSee('Clearer diagnostic explanation observed');
        [$v,$otherManager,$brief] = $this->fixture('observation-note');
        $this->actingAs($otherManager)->post('/staff-development/'.$v->id.'/impact', $this->values($brief))->assertSessionHasErrors('entry_id');
    }

    public function test_existing_impact_remains_private_if_underlying_observation_sharing_is_revoked(): void
    {
        [$u,$m,$e] = $this->fixture('observation', 'In progress', true);
        $url = '/staff-development/'.$u->id;
        $this->actingAs($m)->post($url.'/impact', $this->values($e, ['shared' => 1]))->assertRedirect()->assertSessionHasNoErrors();
        $this->post($url.'/entry', ['version' => 3, 'entry_id' => $e['id'], 'kind' => 'observation', 'date' => today()->toDateString(), 'title' => $e['title'], 'summary' => 'Amended private observation', 'shared' => 0])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($u)->get($url)->assertOk()->assertDontSee('Clearer diagnostic explanation observed')->assertDontSee('Amended private observation');
        $this->actingAs($m)->get($url.'?view=manager')->assertOk()->assertSee('Clearer diagnostic explanation observed');
    }

    public function test_planned_review_reschedule_and_cancel_require_reason_and_keep_history(): void
    {
        [$u,$m] = $this->fixture();
        $url = '/staff-development/'.$u->id;
        $this->actingAs($m)->post($url.'/schedule', ['version' => 2, 'kind' => 'appraisal', 'due' => today()->addDays(7)->toDateString()])->assertRedirect()->assertSessionHasNoErrors();
        $p = Profile::where('user_id', $u->id)->firstOrFail();
        $id = $p->payload['schedules'][0]['id'];
        $v = ['version' => 3, 'schedule_id' => $id, 'change' => 'reschedule', 'due' => today()->addDays(14)->toDateString()];
        $this->post($url.'/schedule-change', $v)->assertSessionHasErrors('reason');
        $this->post($url.'/schedule-change', [...$v, 'reason' => 'Agreed availability changed'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post($url.'/schedule-change', [...$v, 'reason' => 'Stale request'])->assertSessionHasErrors('version');
        $this->post($url.'/schedule-change', ['version' => 4, 'schedule_id' => $id, 'change' => 'cancel', 'reason' => 'Booking no longer needed'])->assertRedirect()->assertSessionHasNoErrors();
        $p = $p->fresh();
        $this->assertSame('Cancelled', $p->payload['schedules'][0]['status']);
        $this->assertCount(2, $p->payload['schedules'][0]['history']);
        $this->get($url.'?view=manager')->assertOk()->assertSee('Cancelled');
    }

    public function test_cancelled_staff_booking_cannot_be_completed_and_employee_cannot_change_booking(): void
    {
        [$u,$m] = $this->fixture();
        $url = '/staff-development/'.$u->id;
        $this->actingAs($m)->post($url.'/schedule', ['version' => 2, 'kind' => 'observation', 'due' => today()->toDateString()])->assertRedirect()->assertSessionHasNoErrors();
        $id = Profile::where('user_id', $u->id)->firstOrFail()->payload['schedules'][0]['id'];
        $data = ['version' => 3, 'schedule_id' => $id, 'change' => 'cancel', 'reason' => 'Fictional booking correction'];
        $this->actingAs($u)->post($url.'/schedule-change', $data)->assertForbidden();
        $this->actingAs($m)->post($url.'/schedule-change', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post($url.'/entry', ['version' => 4, 'kind' => 'observation', 'date' => today()->toDateString(), 'title' => 'Actual observation', 'summary' => 'Fictional notes', 'schedule_id' => $id])->assertSessionHasErrors('schedule_id');
    }
}
