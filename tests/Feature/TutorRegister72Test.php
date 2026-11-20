<?php

namespace Tests\Feature;

use App\Models\{Apprentice, RegisterTrainingRecord, TrainingSession, TutorGroup, User};
use App\Support\TrainingJourney72;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class TutorRegister72Test extends TestCase
{
    use LazilyRefreshDatabase;

    private function tutor(): User
    {
        return User::factory()->create(['role' => 'tutor', 'active' => true, 'email_verified_at' => now()]);
    }

    public function test_structured_delivery_saves_atomically_and_banks_across_groups_with_catchup(): void
    {
        $tutor = $this->tutor();
        $otherTutor = $this->tutor();
        $mondayGroup = TutorGroup::factory()->create(['tutor_id' => $tutor->id, 'active' => true]);
        $catchupGroup = TutorGroup::factory()->create(['tutor_id' => $tutor->id, 'active' => true]);

        $alex = Apprentice::factory()->create(['tutor_id' => $tutor->id, 'tutor_group_id' => $mondayGroup->id]);
        $sam = Apprentice::factory()->create(['tutor_id' => $tutor->id, 'tutor_group_id' => $mondayGroup->id]);
        $unassigned = Apprentice::factory()->create(['tutor_id' => $otherTutor->id]);

        $session1 = TrainingSession::create([
            'tutor_group_id' => $mondayGroup->id,
            'created_by' => $tutor->id,
            'name' => 'Monday workshop',
            'session_date' => now()->subDays(2)->toDateString(),
            'start_time' => '09:00',
            'end_time' => '12:00',
            'breaks' => [],
            'planned_learning' => 'Block 1 inspection',
            'members' => [$alex->id, $sam->id],
            'state' => 'Planned',
            'version' => 1,
        ]);

        $this->actingAs($tutor)->withSession(['auth_version' => $tutor->auth_version]);
        $this->get(route('attendance.show', $session1))->assertOk()->assertSee('Structured training delivery for personal training bank');

        // Save attendance + structured subjects in one request: Alex attended, Sam absent
        $this->post(route('attendance.save', $session1), [
            'version' => 1,
            'delivered_learning' => 'Block 1 workshop inspection',
            'training_subjects' => ['autocare:block-1', 'cc-safeguarding'],
            'training_coverage' => ['autocare:block-1' => 'delivered', 'cc-safeguarding' => 'delivered'],
            'training_confirmed' => '1',
            'marks' => [
                $alex->id => ['code' => 'O', 'arrival' => '09:00', 'departure' => '12:00'],
                $sam->id => ['code' => 'A'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $record = RegisterTrainingRecord::findOrFail($session1->id);
        $this->assertSame($session1->fresh()->version, $record->register_version);

        $alexJourney = TrainingJourney72::forApprentice($alex);
        $samJourney = TrainingJourney72::forApprentice($sam);
        $this->assertSame(1, $alexJourney['counts']['delivered']);
        $this->assertSame(0, $samJourney['counts']['delivered']);

        // Catch-up session in a different group delivers block 1 to Sam
        $session2 = TrainingSession::create([
            'tutor_group_id' => $catchupGroup->id,
            'created_by' => $tutor->id,
            'name' => 'Friday catch-up',
            'session_date' => now()->subDay()->toDateString(),
            'start_time' => '13:00',
            'end_time' => '16:00',
            'breaks' => [],
            'planned_learning' => 'Block 1 catch-up',
            'members' => [$sam->id],
            'state' => 'Planned',
            'version' => 1,
        ]);

        $this->post(route('attendance.save', $session2), [
            'version' => 1,
            'delivered_learning' => 'Block 1 catch-up',
            'training_subjects' => ['autocare:block-1'],
            'training_coverage' => ['autocare:block-1' => 'delivered'],
            'training_confirmed' => '1',
            'marks' => [
                $sam->id => ['code' => 'O', 'arrival' => '13:00', 'departure' => '16:00'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $samAfter = TrainingJourney72::forApprentice($sam);
        $this->assertSame(1, $samAfter['counts']['delivered']);
        $this->assertSame('Friday catch-up', $session2->name);

        // Journey view and Gaps report stay strictly scoped to assigned apprentices
        $this->get(route('training-journey72.show', $sam))->assertOk()->assertSee($catchupGroup->name);
        $this->get(route('training-journey72.show', $unassigned))->assertForbidden();
        $this->get(route('training-gaps72.index'))->assertOk()
            ->assertSee($alex->reference)
            ->assertSee($sam->reference)
            ->assertDontSee($unassigned->reference);
    }
}
