<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Apprentice;
use App\Models\ProgressReview;
use App\Models\ReviewSignature;
use App\Models\Standard;
use App\Models\User;
use App\Services\ReviewScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewsAndTripartiteSignaturesTest extends TestCase
{
    use RefreshDatabase;

    protected User $tutor;
    protected User $mentor;
    protected User $employerUser;
    protected User $apprenticeUser;
    protected Apprentice $apprentice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tutor = User::factory()->create(['role' => UserRole::Trainer]);
        $this->mentor = User::factory()->create(['role' => UserRole::Mentor]);
        $this->employerUser = User::factory()->create(['role' => UserRole::Employer]);
        $this->apprenticeUser = User::factory()->create(['role' => UserRole::Apprentice]);

        $standard = Standard::create([
            'standard_code' => 'ST0482',
            'title' => 'Autocare Technician',
            'total_otj_target' => 663.00,
        ]);

        $this->apprentice = Apprentice::create([
            'user_id' => $this->apprenticeUser->id,
            'trainer_id' => $this->tutor->id,
            'standard_id' => $standard->standard_id,
            'start_date' => Carbon::parse('2026-01-05'),
        ]);
    }

    public function test_review_cadence_calculation_10_weeks_and_70_days(): void
    {
        $scheduler = app(ReviewScheduleService::class);

        // First review: 10 weeks from 2026-01-05 = 2026-03-16
        $firstDueDate = $scheduler->calculateNextDueDate($this->apprentice);
        $this->assertEquals('2026-03-16', $firstDueDate->toDateString());

        // Conduct Review #1 with actual meeting date on 2026-03-20
        $review1 = ProgressReview::create([
            'apprentice_id' => $this->apprentice->apprentice_id,
            'trainer_id' => $this->tutor->id,
            'review_sequence' => 1,
            'due_date' => $firstDueDate->toDateString(),
            'meeting_date' => '2026-03-20',
            'status' => 'Conducted',
        ]);

        // Subsequent Review #2 due 70 days after actual meeting date: 2026-03-20 + 70 days = 2026-05-29
        $secondDueDate = $scheduler->calculateNextDueDate($this->apprentice);
        $this->assertEquals('2026-05-29', $secondDueDate->toDateString());
    }

    public function test_tripartite_digital_signatures_flow(): void
    {
        $review = ProgressReview::create([
            'apprentice_id' => $this->apprentice->apprentice_id,
            'trainer_id' => $this->tutor->id,
            'review_sequence' => 1,
            'due_date' => '2026-03-16',
            'meeting_date' => '2026-03-16',
            'progress_summary' => 'Solid technical progress on foundation tasks; good workplace attendance.',
            'status' => 'Conducted',
        ]);

        // 1. Apprentice Signs
        $resp1 = $this->actingAs($this->apprenticeUser)->post(route('reviews.sign', $review->id), [
            'signatory_name' => 'Test Learner One',
        ]);
        $resp1->assertRedirect();
        $this->assertDatabaseHas('review_signatures', [
            'review_id' => $review->id,
            'party_role' => 'apprentice',
            'signatory_name' => 'Test Learner One',
        ]);
        $this->assertEquals('Conducted', $review->fresh()->status); // Not yet fully signed

        // 2. Employer Signs
        $resp2 = $this->actingAs($this->employerUser)->post(route('reviews.sign', $review->id), [
            'signatory_name' => 'David Workshop Manager',
        ]);
        $resp2->assertRedirect();
        $this->assertDatabaseHas('review_signatures', [
            'review_id' => $review->id,
            'party_role' => 'employer',
            'signatory_name' => 'David Workshop Manager',
        ]);
        $this->assertEquals('Conducted', $review->fresh()->status); // Still waiting for Mentor

        // 3. Learning & Progress Mentor Signs
        $resp3 = $this->actingAs($this->mentor)->post(route('reviews.sign', $review->id), [
            'signatory_name' => 'Jordan Reviewer',
        ]);
        $resp3->assertRedirect();
        $this->assertDatabaseHas('review_signatures', [
            'review_id' => $review->id,
            'party_role' => 'mentor',
            'signatory_name' => 'Jordan Reviewer',
        ]);

        // When all 3 parties have signed, status becomes 'Signed'
        $this->assertEquals('Signed', $review->fresh()->status);

        // Ensure cryptographic SHA-256 hash was generated
        $sig = ReviewSignature::where('review_id', $review->id)->first();
        $this->assertEquals(64, strlen($sig->document_snapshot_hash));
    }
}

