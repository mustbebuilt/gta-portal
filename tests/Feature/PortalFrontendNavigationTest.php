<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Apprentice;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalFrontendNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $tutor;
    protected User $apprenticeUser;
    protected Apprentice $apprentice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->tutor = User::factory()->create(['role' => UserRole::Trainer]);
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
            'start_date' => now()->toDateString(),
        ]);
    }

    public function test_public_pages_render_correctly(): void
    {
        $this->get(route('welcome'))->assertOk()->assertSee('Doncaster &amp; Sheffield Motor Trades GTA', false);
        $this->get(route('login'))->assertOk()->assertSee('Sign in to Portal');
    }

    public function test_apprentice_can_navigate_portal_sections(): void
    {
        $this->actingAs($this->apprenticeUser)->get(route('dashboard'))->assertOk()->assertSee('My Apprenticeship Workspace');
        $this->actingAs($this->apprenticeUser)->get(route('tasks.index'))->assertOk()->assertSee('10-Block Training Curriculum');
        $this->actingAs($this->apprenticeUser)->get(route('attendance.index'))->assertOk()->assertSee('Attendance &amp; Session Registers', false);
        $this->actingAs($this->apprenticeUser)->get(route('otj.index'))->assertOk()->assertSee('Off-The-Job (OTJ) Training Tracker');
        $this->actingAs($this->apprenticeUser)->get(route('journey.show'))->assertOk()->assertSee('Apprentice Journey &amp; Visual Roadmap', false);
    }

    public function test_staff_and_admin_routes_render_correctly(): void
    {
        // Tutor views marking queue and case studies
        $this->actingAs($this->tutor)->get(route('marking.index'))->assertOk()->assertSee('Task Marking SLA Queue');
        $this->actingAs($this->tutor)->get(route('case-studies.index'))->assertOk()->assertSee('Anonymous Case Studies Bank');
        $this->actingAs($this->tutor)->get(route('support.index'))->assertOk()->assertSee('Additional Learning Support (ALS)');

        // Admin views inspection overview
        $this->actingAs($this->admin)->get(route('inspection.overview'))->assertOk()->assertSee('Ofsted &amp; Quality Inspection Overview', false);
    }
}

