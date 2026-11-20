<?php

namespace Tests\Feature;

use App\Models\{Apprentice, MonthlyScorecard, TrainingSession, TutorGroup, User};
use App\Support\{ApprenticeReviewData, MonthlyDevelopment};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ProgressReview74Test extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_register_saves_optional_monthly_development_and_review_summary_displays_it(): void
    {
        $tutor = User::factory()->create(['role' => 'tutor', 'active' => true, 'email_verified_at' => now()]);
        $otherTutor = User::factory()->create(['role' => 'tutor', 'active' => true, 'email_verified_at' => now()]);
        $group = TutorGroup::factory()->create(['tutor_id' => $tutor->id, 'active' => true]);
        $apprentice = Apprentice::factory()->create([
            'tutor_id' => $tutor->id,
            'tutor_group_id' => $group->id,
            'start_date' => now()->subMonths(2)->toDateString(),
            'planned_end_date' => now()->addMonths(12)->toDateString(),
        ]);

        $session = TrainingSession::create([
            'tutor_group_id' => $group->id,
            'created_by' => $tutor->id,
            'name' => 'Workshop session',
            'session_date' => now()->subDay()->toDateString(),
            'start_time' => '09:00',
            'end_time' => '12:00',
            'breaks' => [],
            'planned_learning' => 'Inspection skills',
            'members' => [$apprentice->id],
            'state' => 'Planned',
            'version' => 1,
        ]);

        $ratings = array_fill_keys(array_keys(ApprenticeReviewData::DEVELOPMENT_AREAS), 'Consistently demonstrated');

        $this->actingAs($tutor)->withSession(['auth_version' => $tutor->auth_version]);
        $this->post(route('attendance.save', $session), [
            'version' => 1,
            'delivered_learning' => 'Inspection skills',
            'marks' => [
                $apprentice->id => ['code' => 'O', 'arrival' => '09:00', 'departure' => '12:00'],
            ],
            'monthly_dev' => [
                $apprentice->id => [
                    'save' => '1',
                    'version' => 0,
                    'ratings' => $ratings,
                    'comment' => 'Strong safe working and initiative.',
                ],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $card = MonthlyScorecard::where('apprentice_id', $apprentice->id)->firstOrFail();
        $dev = MonthlyDevelopment::read($card);
        $this->assertSame('gta-development-v2', $dev['schema']);
        $this->assertSame('Strong safe working and initiative.', $dev['comment']);

        $this->get(route('progress-review74.show', $apprentice))
            ->assertOk()
            ->assertSee('Apprentice Progress Review (Pack 74)')
            ->assertSee('Strong safe working and initiative.')
            ->assertSee('Consistently demonstrated');

        // Unassigned tutor cannot open review summary
        $this->actingAs($otherTutor)->withSession(['auth_version' => $otherTutor->auth_version]);
        $this->get(route('progress-review74.show', $apprentice))->assertForbidden();
    }
}
