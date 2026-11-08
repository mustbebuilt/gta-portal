<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Apprentice;
use App\Models\CaseStudy;
use App\Models\ProgressReview;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InspectionOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $tutor;
    protected User $employer;
    protected User $apprenticeUser;
    protected Apprentice $apprentice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->tutor = User::factory()->create(['role' => UserRole::Trainer]);
        $this->employer = User::factory()->create(['role' => UserRole::Employer]);
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
            'start_date' => now()->subMonths(4)->toDateString(),
        ]);
    }

    public function test_non_admins_are_forbidden_from_inspection_overview(): void
    {
        // Apprentice is forbidden
        $this->actingAs($this->apprenticeUser)->get(route('inspection.overview'))->assertForbidden();

        // Employer is forbidden
        $this->actingAs($this->employer)->get(route('inspection.overview'))->assertForbidden();

        // Routine tutor is forbidden (Admin / Executive oversight only)
        $this->actingAs($this->tutor)->get(route('inspection.overview'))->assertForbidden();
    }

    public function test_administrator_can_view_inspection_overview_and_print_dossier(): void
    {
        // Add a signed review
        ProgressReview::create([
            'apprentice_id' => $this->apprentice->apprentice_id,
            'trainer_id' => $this->tutor->id,
            'review_sequence' => 1,
            'due_date' => now()->subDays(10)->toDateString(),
            'meeting_date' => now()->subDays(10)->toDateString(),
            'status' => 'Signed',
        ]);

        // Add an approved case study
        CaseStudy::create([
            'author_user_id' => $this->tutor->id,
            'approved_by_user_id' => $this->admin->id,
            'title' => 'Ofsted Approved Technical Workshop Case',
            'category' => 'Teaching and assessment',
            'challenge' => 'Brake circuit diagnostics',
            'starting_point' => 'Initial difficulty with hydraulic circuit diagrams',
            'response' => 'Hands-on rig training',
            'before_state' => '40% diagnostic score',
            'before_date' => now()->subMonths(1)->toDateString(),
            'after_state' => '90% diagnostic score',
            'after_date' => now()->toDateString(),
            'evidence' => 'Workshop assessment sheets',
            'outcome' => 'Progress improved',
            'status' => 'Approved',
            'approved_at' => now(),
        ]);

        // Admin views overview
        $response = $this->actingAs($this->admin)->get(route('inspection.overview'));
        $response->assertOk();
        $response->assertSee('Ofsted &amp; Quality Inspection Overview', false);
        $response->assertSee('Ofsted Approved Technical Workshop Case');
        $response->assertSee('100%'); // 1/1 reviews signed

        // Admin views print dossier
        $printResponse = $this->actingAs($this->admin)->get(route('inspection.print'));
        $printResponse->assertOk();
        $printResponse->assertSee('Quality Inspection &amp; Statutory Compliance Dossier', false);
        $printResponse->assertSee('Ofsted Approved Technical Workshop Case');
    }
}

