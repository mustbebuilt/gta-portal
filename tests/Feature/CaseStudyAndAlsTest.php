<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Apprentice;
use App\Models\CaseStudy;
use App\Models\SupportPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CaseStudyAndAlsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin1;
    protected User $admin2;
    protected User $tutor;
    protected User $employer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin1 = User::factory()->create(['role' => UserRole::Admin]);
        $this->admin2 = User::factory()->create(['role' => UserRole::Admin]);
        $this->tutor = User::factory()->create(['role' => UserRole::Trainer]);
        $this->employer = User::factory()->create(['role' => UserRole::Employer]);
    }

    public function test_authoring_case_study_and_different_administrator_rule(): void
    {
        // 1. Admin 1 authors a case study
        $createResp = $this->actingAs($this->admin1)->post(route('case-studies.store'), [
            'title' => 'Maths and English Workshop Scaffolding',
            'category' => 'Maths and English',
            'challenge' => 'Struggling with technical gear ratio calculations',
            'starting_point' => 'Diagnostic assessment at 35%',
            'response' => 'Created step-by-step ratio laminated workshop cards',
            'before_state' => '35% test mark',
            'before_date' => now()->subMonths(2)->toDateString(),
            'after_state' => '82% test mark',
            'after_date' => now()->toDateString(),
            'evidence' => 'Marked Task 1 paper assessment logs',
            'outcome' => 'Progress improved',
            'submit_for_approval' => 1,
        ]);

        $createResp->assertRedirect();
        $case = CaseStudy::where('title', 'Maths and English Workshop Scaffolding')->first();
        $this->assertNotNull($case);
        $this->assertEquals('Pending Approval', $case->status);

        // 2. Admin 1 (the author) attempts to self-approve -> MUST BE FORBIDDEN
        $selfApproveResp = $this->actingAs($this->admin1)->post(route('case-studies.approve', $case->id));
        $selfApproveResp->assertForbidden();
        $this->assertEquals('Pending Approval', $case->fresh()->status);

        // 3. Admin 2 (a DIFFERENT administrator) approves -> MUST SUCCEED
        $peerApproveResp = $this->actingAs($this->admin2)->post(route('case-studies.approve', $case->id));
        $peerApproveResp->assertRedirect();
        $this->assertEquals('Approved', $case->fresh()->status);
        $this->assertEquals($this->admin2->id, $case->fresh()->approved_by_user_id);
    }

    public function test_employer_strictly_excluded_from_support_plans(): void
    {
        $apprenticeUser = User::factory()->create(['role' => UserRole::Apprentice]);
        $apprentice = Apprentice::create([
            'user_id' => $apprenticeUser->id,
            'start_date' => now()->toDateString(),
        ]);

        SupportPlan::create([
            'apprentice_id' => $apprentice->apprentice_id,
            'plan_number' => 1,
            'primary_support_need' => 'Dyslexia & Processing Accommodation',
            'authorized_teaching_adjustments' => 'Provide printed notes on pale yellow paper and allow 25% extra test time.',
            'confidential_case_notes' => 'Confidential clinical assessment notes.',
        ]);

        // Employer attempts to access support plans roster -> 403 Forbidden
        $response = $this->actingAs($this->employer)->get(route('support.index'));
        $response->assertForbidden();

        // Routine tutor can access support roster to view teaching instructions
        $tutorResp = $this->actingAs($this->tutor)->get(route('support.index'));
        $tutorResp->assertOk();
        $tutorResp->assertSee('Dyslexia & Processing Accommodation');
    }
}

