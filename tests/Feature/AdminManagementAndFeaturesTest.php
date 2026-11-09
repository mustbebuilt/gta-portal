<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Apprentice;
use App\Models\Employer;
use App\Models\Standard;
use App\Models\TutorGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminManagementAndFeaturesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $trainer;
    protected User $employerUser;
    protected User $apprenticeUser;
    protected Apprentice $apprentice;
    protected Employer $employer;
    protected Standard $standard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => UserRole::Admin,
            'email' => 'admin.test@gta-portal.test',
        ]);

        $this->trainer = User::factory()->create([
            'role' => UserRole::Trainer,
            'email' => 'trainer.test@gta-portal.test',
        ]);

        $this->employerUser = User::factory()->create([
            'role' => UserRole::Employer,
            'email' => 'employer.test@gta-portal.test',
        ]);

        $this->employer = Employer::create([
            'employer_name' => 'Apex Motors',
            'contact_email' => 'employer.test@gta-portal.test',
        ]);

        $this->standard = Standard::create([
            'standard_code' => 'ST0482',
            'title' => 'Autocare Technician',
            'level' => 2,
        ]);

        $this->apprenticeUser = User::factory()->create([
            'role' => UserRole::Apprentice,
            'email' => 'apprentice.test@gta-portal.test',
        ]);

        $this->apprentice = Apprentice::create([
            'user_id' => $this->apprenticeUser->id,
            'employer_id' => $this->employer->employer_id,
            'trainer_id' => $this->trainer->id,
            'standard_id' => $this->standard->standard_id,
            'start_date' => now()->subMonths(3)->toDateString(),
            'planned_end_date' => now()->addMonths(21)->toDateString(),
            'otj_target_hours' => 663.00,
            'otj_hours_completed' => 50.00,
            'status' => 'Live',
        ]);
    }

    public function test_admin_can_access_admin_hub_and_menu_contains_all_required_admin_features(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin');
        $response->assertStatus(200);
        $response->assertSee('Administrator Center');
        $response->assertSee('Apprentices List &amp; Add Apprentice', false);
        $response->assertSee('Group Creation &amp; Tutor Assignments', false);
        $response->assertSee('Careers &amp; Progression', false);
        $response->assertSee('Staff Development', false);
        $response->assertSee('General Reports Area', false);
        $response->assertSee('Help Guides', false);

        // Verify that Admin menu system contains all requested links
        $response->assertSee('Apprentices list');
        $response->assertSee('Add apprentice');
        $response->assertSee('Group creation &amp; tutors', false);
        $response->assertSee('Careers');
        $response->assertSee('Staff development');
        $response->assertSee('A general reports area');
        $response->assertSee('Help guides');
    }

    public function test_admin_can_access_apprentices_list_and_create_page(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/apprentices');
        $response->assertStatus(200);
        $response->assertSee('Apprentices Directory');

        $responseCreate = $this->actingAs($this->admin)->get('/admin/apprentices/create');
        $responseCreate->assertStatus(200);
        $responseCreate->assertSee('Add Apprentice &amp; Setup Programme', false);
    }

    public function test_non_admin_cannot_access_apprentices_management(): void
    {
        $response = $this->actingAs($this->apprenticeUser)->get('/admin/apprentices');
        $response->assertStatus(403);

        $response2 = $this->actingAs($this->employerUser)->get('/admin/apprentices');
        $response2->assertStatus(403);
    }

    public function test_admin_can_enroll_apprentice_and_tasks_are_initialized(): void
    {
        $response = $this->actingAs($this->admin)->post('/admin/apprentices', [
            'first_name' => 'Charlie',
            'last_name' => 'Baker',
            'email' => 'charlie.baker@gta-portal.test',
            'username' => 'charlie_b',
            'trainer_id' => $this->trainer->id,
            'employer_id' => $this->employer->employer_id,
            'standard_id' => $this->standard->standard_id,
            'start_date' => now()->toDateString(),
            'planned_end_date' => now()->addMonths(24)->toDateString(),
            'otj_target_hours' => 663.00,
        ]);

        $response->assertRedirect('/admin/apprentices');

        $newUser = User::where('email', 'charlie.baker@gta-portal.test')->first();
        $this->assertNotNull($newUser);

        $newApprentice = Apprentice::where('user_id', $newUser->id)->first();
        $this->assertNotNull($newApprentice);
        $this->assertEquals(50, $newApprentice->tasks()->count());
    }

    public function test_admin_can_manage_tutor_groups(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/groups');
        $response->assertStatus(200);
        $response->assertSee('Manage Tutor Groups &amp; Allocations', false);

        $postResponse = $this->actingAs($this->admin)->post('/admin/groups', [
            'group_name' => 'Cohort 2026-B',
            'group_code' => 'COHORT-26B',
            'tutor_id' => $this->trainer->id,
            'notes' => 'Thursday group',
        ]);

        $postResponse->assertRedirect('/admin/groups');
        $this->assertDatabaseHas('tutor_groups', ['group_code' => 'COHORT-26B']);
    }

    public function test_employer_is_strictly_forbidden_from_careers(): void
    {
        $response = $this->actingAs($this->employerUser)->get('/careers');
        $response->assertStatus(403);
    }

    public function test_apprentice_and_staff_can_access_careers(): void
    {
        $responseApprentice = $this->actingAs($this->apprenticeUser)->get('/careers');
        $responseApprentice->assertStatus(200);
        $responseApprentice->assertSee('Careers, Advice &amp; Progression', false);

        $responseStaff = $this->actingAs($this->trainer)->get('/careers');
        $responseStaff->assertStatus(200);
    }

    public function test_staff_can_access_staff_development_and_reports(): void
    {
        $responseDev = $this->actingAs($this->trainer)->get('/staff-development');
        $responseDev->assertStatus(200);
        $responseDev->assertSee('Staff Development &amp; CPD', false);

        $responseReports = $this->actingAs($this->trainer)->get('/reports');
        $responseReports->assertStatus(200);
        $responseReports->assertSee('Reporting Home &amp; Directory', false);
    }

    public function test_help_guides_accessible_to_users(): void
    {
        $response = $this->actingAs($this->apprenticeUser)->get('/help');
        $response->assertStatus(200);
        $response->assertSee('Help &amp; How To Guides', false);
    }
}

