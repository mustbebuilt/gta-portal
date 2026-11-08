<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Apprentice;
use App\Models\ApprenticeTask;
use App\Models\CaseStudy;
use App\Models\Employer;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class PrivacyAndRolePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_employer_cannot_view_confidential_support_notes(): void
    {
        $employerUser = User::factory()->create(['role' => UserRole::Employer]);
        $apprenticeUser = User::factory()->create(['role' => UserRole::Apprentice]);
        $apprentice = Apprentice::create([
            'user_id' => $apprenticeUser->id,
            'start_date' => now()->toDateString(),
        ]);

        // Employer must be denied access to confidential ALS / support notes
        $this->assertFalse(
            Gate::forUser($employerUser)->allows('viewConfidentialSupport', $apprentice)
        );
    }

    public function test_employer_cannot_mark_apprentice_task(): void
    {
        $employerUser = User::factory()->create(['role' => UserRole::Employer]);
        $tutorUser = User::factory()->create(['role' => UserRole::Trainer]);
        $apprenticeUser = User::factory()->create(['role' => UserRole::Apprentice]);

        $apprentice = Apprentice::create([
            'user_id' => $apprenticeUser->id,
            'trainer_id' => $tutorUser->id,
        ]);

        $task = ApprenticeTask::create([
            'apprentice_id' => $apprentice->apprentice_id,
            'ablk_id' => 1,
            'task_num' => 1,
            'task_type' => 'Task 1: Knowledge Assessment',
            'status' => 'Submitted',
        ]);

        // Employer cannot mark/approve
        $this->assertFalse(
            Gate::forUser($employerUser)->allows('mark', $task)
        );

        // Assigned tutor can mark/approve
        $this->assertTrue(
            Gate::forUser($tutorUser)->allows('mark', $task)
        );
    }

    public function test_case_study_requires_different_administrator_approval(): void
    {
        $authorAdmin = User::factory()->create(['role' => UserRole::Admin]);
        $differentAdmin = User::factory()->create(['role' => UserRole::Admin]);
        $trainer = User::factory()->create(['role' => UserRole::Trainer]);

        $caseStudy = CaseStudy::create([
            'author_user_id' => $authorAdmin->id,
            'title' => 'Test Inclusive Support Adaptation',
            'category' => 'Additional learning support',
            'challenge' => 'Overcoming practical workplace anxiety',
            'starting_point' => 'Low task engagement',
            'response' => 'Structured 1:1 mentor coaching',
            'before_state' => 'Task completion at 20%',
            'before_date' => now()->subMonths(3)->toDateString(),
            'after_state' => 'Task completion at 85%',
            'after_date' => now()->toDateString(),
            'evidence' => 'Workshop logs and mentor feedback',
            'outcome' => 'Progress improved',
            'status' => 'Pending Approval',
        ]);

        // Authoring admin CANNOT approve their own case study
        $this->assertFalse(
            Gate::forUser($authorAdmin)->allows('approve', $caseStudy)
        );

        // Trainers/Tutors cannot approve case studies
        $this->assertFalse(
            Gate::forUser($trainer)->allows('approve', $caseStudy)
        );

        // A DIFFERENT administrator CAN approve the case study
        $this->assertTrue(
            Gate::forUser($differentAdmin)->allows('approve', $caseStudy)
        );
    }
}

