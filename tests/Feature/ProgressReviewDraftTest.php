<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\AttendanceMark;
use App\Models\ProgressReviewDraft;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ProgressReviewDraftTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role = 'admin'): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->save();

        return $u;
    }

    public function test_assigned_mentor_saves_reopens_draft_and_proposed_seventy_day_reference(): void
    {
        $u = $this->actor('mentor');
        $a = Apprentice::factory()->create(['mentor_id' => $u->id]);
        $this->actingAs($u)->get('/reviews')->assertOk()->assertSee($a->first_name);
        $this->get('/apprentices/'.$a->id.'/reviews/new')->assertOk()->assertSee('Progress review draft');
        $this->post('/apprentices/'.$a->id.'/reviews', ['fields' => ['reviewDate' => '2026-11-01', 'summary' => 'Fictional three-way discussion', 'judgement' => 'Unable to confirm']])->assertRedirect()->assertSessionHasNoErrors();
        $row = ProgressReviewDraft::firstOrFail();
        $this->assertSame('2027-01-10', $row->fields['nextReview']);
        $this->get('/apprentices/'.$a->id.'/reviews/'.$row->id)->assertOk()->assertSee('Fictional three-way discussion')->assertSee('Not a completed review');
    }

    public function test_apprentice_employer_and_unassigned_mentor_cannot_see_private_draft(): void
    {
        $a = Apprentice::factory()->create();
        $row = ProgressReviewDraft::factory()->create(['apprentice_id' => $a->id]);
        foreach (['apprentice', 'employer', 'mentor'] as $role) {
            $u = $this->actor($role);
            if ($role === 'apprentice') {
                $a->user_id = $u->id;
                $a->save();
            }$this->actingAs($u)->get('/apprentices/'.$a->id.'/reviews/'.$row->id)->assertForbidden();
            $this->post('/apprentices/'.$a->id.'/reviews', ['fields' => ['summary' => 'Wrong scope']])->assertForbidden();
        }
    }

    public function test_stale_and_nested_draft_updates_cannot_override_saved_record(): void
    {
        $a = Apprentice::factory()->create();
        $row = ProgressReviewDraft::factory()->create(['apprentice_id' => $a->id]);
        $other = Apprentice::factory()->create();
        $this->actingAs($this->actor());
        $url = '/apprentices/'.$a->id.'/reviews/'.$row->id;
        $this->post($url, ['version' => 1, 'fields' => ['summary' => 'Saved version']])->assertRedirect()->assertSessionHasNoErrors();
        $this->post($url, ['version' => 1, 'fields' => ['summary' => 'Stale']])->assertSessionHasErrors('version');
        $this->post('/apprentices/'.$other->id.'/reviews/'.$row->id, ['version' => 2, 'fields' => ['summary' => 'Wrong apprentice']])->assertNotFound();
        $this->assertSame('Saved version', $row->fresh()->fields['summary']);
    }

    public function test_source_snapshot_is_preserved_on_reopen_and_has_no_compliance_score(): void
    {
        $a = Apprentice::factory()->create();
        $this->actingAs($this->actor())->post('/apprentices/'.$a->id.'/reviews', ['fields' => ['summary' => 'Draft only']])->assertRedirect()->assertSessionHasNoErrors();
        $row = ProgressReviewDraft::firstOrFail();
        $before = $row->source_snapshot;
        $this->get('/apprentices/'.$a->id.'/reviews/'.$row->id)->assertOk()->assertSee('No assigned tasks in the snapshot');
        $this->assertSame($before, $row->fresh()->source_snapshot);
        $this->assertSame(false, $row->source_snapshot['forecast_complete']);
        $this->assertArrayNotHasKey('compliance_score', $row->source_snapshot);
    }

    public function test_training_period_and_future_actual_meeting_dates_are_validated(): void
    {
        $a = Apprentice::factory()->create();
        $this->actingAs($this->actor())->post('/apprentices/'.$a->id.'/reviews', ['fields' => ['reviewDate' => '2026-11-01', 'trainingFrom' => '2026-11-02']])->assertSessionHasErrors('fields.trainingFrom');
        $this->post('/apprentices/'.$a->id.'/reviews', ['fields' => ['reviewDate' => today()->addDay()->toDateString()]])->assertSessionHasErrors('fields.reviewDate');
    }

    public function test_actual_delivered_and_planned_subjects_come_from_the_register_with_distinct_labels(): void
    {
        $a = Apprentice::factory()->create();
        $past = TrainingSession::factory()->create(['members' => [$a->id], 'session_date' => '2026-11-01']);
        $past->delivered_learning = 'Actual delivered inspection subject';
        $past->save();
        $u = $this->actor();
        AttendanceMark::create(['training_session_id' => $past->id, 'apprentice_id' => $a->id, 'code' => 'O', 'recorded_by' => $u->id]);
        TrainingSession::factory()->create(['members' => [$a->id], 'session_date' => '2026-11-15', 'planned_learning' => 'Booked future tyre subject']);
        $this->actingAs($u)->post('/apprentices/'.$a->id.'/reviews', ['fields' => ['reviewDate' => '2026-11-02', 'trainingFrom' => '2026-11-01']])->assertRedirect()->assertSessionHasNoErrors();
        $row = ProgressReviewDraft::firstOrFail();
        $this->assertSame('Actual delivered inspection subject', $row->source_snapshot['delivered'][0]['subjects']);
        $this->assertSame('Booked future tyre subject', $row->source_snapshot['planned'][0]['subjects']);
        $this->get('/apprentices/'.$a->id.'/reviews/'.$row->id)->assertOk()->assertSee('Partial bookings only');
    }
}
