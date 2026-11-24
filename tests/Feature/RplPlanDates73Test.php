<?php

namespace Tests\Feature;

use App\Models\{Apprentice, ApprenticeSetupDraft68, Employer, IndividualDeliveryPlan, Programme, ProgrammeSetupTemplate68, User};
use App\Support\Rpl68;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class RplPlanDates73Test extends TestCase
{
    use LazilyRefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create(['role' => 'admin', 'active' => true, 'email_verified_at' => now()]);
        return $u;
    }

    private function setupAutocareTemplate(): array
    {
        $programme = Programme::create([
            'standard_code' => 'ST0499',
            'version' => '1.3',
            'name' => 'Autocare Technician',
            'level' => 2,
            'active' => true,
        ]);
        $data = json_decode(file_get_contents(resource_path('data/setup68-templates/ST0499_v1.3_autocare.json')), true, 512, JSON_THROW_ON_ERROR);
        $data['source_approval_ref'] = 'TEST73';
        $template = ProgrammeSetupTemplate68::create([
            'programme_id' => $programme->id,
            'template' => $data,
            'source_hash' => Rpl68::hash($data),
            'version' => 1,
            'active' => true,
        ]);

        return [$programme, $template];
    }

    public function test_step4_places_ksb_editor_before_remaining_confirmation_and_removes_date_panel_from_steps_3_and_4(): void
    {
        config(['gta_rpl_dates' => ['ST0499:1.3' => ['hours_per_training_week' => 6, 'training_weeks_per_year' => 46.4, 'epa_months' => 1]]]);
        $admin = $this->admin();
        [, $template] = $this->setupAutocareTemplate();
        $employer = Employer::factory()->create(['active' => true]);

        $this->actingAs($admin)->withSession(['auth_version' => $admin->auth_version]);
        $this->post(route('setup68.create'))->assertRedirect();
        $draft = ApprenticeSetupDraft68::firstOrFail();

        $this->post(route('setup68.save', [$draft, 3]), [
            'version' => $draft->version,
            'data' => ['template_id' => $template->id, 'start_date' => '2027-01-11'],
        ])->assertRedirect();

        $step3 = $this->get(route('setup68.show', [$draft->fresh(), 3]))->assertOk();
        $step3->assertDontSee('Save agreed dates')->assertDontSee('Programme timetable');

        $step4 = $this->get(route('setup68.show', [$draft->fresh(), 4]))->assertOk();
        $step4->assertDontSee('Save agreed dates')
            ->assertSee('Prior learning decision')
            ->assertSee('No relevant prior learning')
            ->assertSee('Apply assessed prior learning')
            ->assertSee('Choose a KSB');
    }

    public function test_step6_allows_single_confirmation_with_default_and_amended_planned_dates(): void
    {
        config(['gta_rpl_dates' => ['ST0499:1.3' => ['hours_per_training_week' => 6, 'training_weeks_per_year' => 46.4, 'epa_months' => 1]]]);
        $admin = $this->admin();
        [, $template] = $this->setupAutocareTemplate();
        $employer = Employer::factory()->create(['active' => true]);

        $this->actingAs($admin)->withSession(['auth_version' => $admin->auth_version]);
        $this->post(route('setup68.create'));
        $draft = ApprenticeSetupDraft68::firstOrFail();

        $this->post(route('setup68.save', [$draft->fresh(), 1]), [
            'version' => $draft->fresh()->version,
            'data' => ['reference' => 'P73-001', 'first_name' => 'Alex', 'last_name' => 'Driver', 'email' => 'alex73@example.test'],
        ]);
        $this->post(route('setup68.save', [$draft->fresh(), 2]), [
            'version' => $draft->fresh()->version,
            'data' => ['state' => 'none'],
        ]);
        $this->post(route('setup68.save', [$draft->fresh(), 3]), [
            'version' => $draft->fresh()->version,
            'data' => ['template_id' => $template->id, 'start_date' => '2027-01-11'],
        ]);
        $this->post(route('setup68.save', [$draft->fresh(), 4]), [
            'version' => $draft->fresh()->version,
            'action' => 'none',
        ]);
        $this->post(route('setup68.save', [$draft->fresh(), 5]), [
            'version' => $draft->fresh()->version,
            'data' => ['id' => $employer->id],
        ]);

        $step6 = $this->get(route('setup68.show', [$draft->fresh(), 6]))->assertOk();
        $step6->assertSee('Functional Skills')
            ->assertSee('Pending (not yet confirmed)');

        $this->post(route('setup68.save', [$draft->fresh(), 6]), [
            'version' => $draft->fresh()->version,
            'next' => 7,
            'data' => ['maths' => 'Yes', 'english' => 'Unknown'],
        ])->assertRedirect(route('setup68.show', [$draft->fresh(), 7]));

        $step7 = $this->get(route('setup68.show', [$draft->fresh(), 7]))->assertOk();
        $step7->assertSee('Check the planned dates proposed from the approved programme and prior learning.')
            ->assertSee('Planned practical end date')
            ->assertSee('Planned apprenticeship end date (including assessment)')
            ->assertSee('Maths: Required at entry')
            ->assertSee('English: Pending (complete later in Maths and English)')
            ->assertSee('Confirm and create apprentice');

        // Reject invalid date order on Step 7 without creating apprentice
        $this->post(route('setup68.confirm', $draft->fresh()), [
            'version' => $draft->fresh()->version,
            'confirmed' => '1',
            'practical_end' => '2029-05-01',
            'programme_end' => '2029-04-01',
        ])->assertSessionHasErrors('programme_end');
        $this->assertSame(0, Apprentice::count());

        // Confirm with amended valid dates in one step
        $this->post(route('setup68.confirm', $draft->fresh()), [
            'version' => $draft->fresh()->version,
            'confirmed' => '1',
            'practical_end' => '2029-04-15',
            'programme_end' => '2029-05-31',
        ])->assertRedirect();

        $apprentice = Apprentice::firstOrFail();
        $this->assertSame('2029-05-31', $apprentice->planned_end_date->toDateString());

        $outcome = \App\Models\ProgrammeOutcome::where('apprentice_id', $apprentice->id)->firstOrFail();
        $this->assertSame('Yes', $outcome->context['maths']);
        $this->assertSame('Unknown', $outcome->context['english']);

        $plan = IndividualDeliveryPlan::where('apprentice_id', $apprentice->id)->firstOrFail();
        $this->assertSame('2029-04-15', $plan->approved['details']['practical_end_date']);
        $this->assertSame('2029-05-31', $plan->approved['details']['apprenticeship_end_date']);
        $this->assertSame('2029-05-31', $plan->approved['blocks'][11]['end']);
        $this->assertCount(12, $plan->approved['blocks']);
    }

    public function test_agreed_timetable_template_confirms_without_manual_12_line_schedule(): void
    {
        config(['gta_rpl_dates' => ['ST0499:1.3' => ['hours_per_training_week' => 6, 'training_weeks_per_year' => 46.4, 'epa_months' => 1]]]);
        $admin = $this->admin();
        [, $template] = $this->setupAutocareTemplate();
        $matrix = $template->template;
        $matrix['date_basis'] = 'agreed_timetable';
        $template->update(['template' => $matrix, 'source_hash' => Rpl68::templateHash($matrix)]);
        $employer = Employer::create(['reference' => 'EMP-TT-73', 'name' => 'Timetable Workshop', 'active' => true]);

        $this->actingAs($admin)->post(route('setup68.create'));
        $draft = ApprenticeSetupDraft68::firstOrFail();
        $this->post(route('setup68.save', [$draft->fresh(), 1]), ['version' => $draft->fresh()->version, 'data' => ['reference' => 'TT73-001', 'first_name' => 'Alex', 'last_name' => 'Timetable']]);
        $this->post(route('setup68.save', [$draft->fresh(), 2]), ['version' => $draft->fresh()->version, 'data' => ['state' => 'none']]);
        $this->post(route('setup68.save', [$draft->fresh(), 3]), ['version' => $draft->fresh()->version, 'data' => ['template_id' => $template->id, 'start_date' => '2027-01-11']]);
        $this->post(route('setup68.save', [$draft->fresh(), 4]), ['version' => $draft->fresh()->version, 'action' => 'none']);
        $this->post(route('setup68.save', [$draft->fresh(), 5]), ['version' => $draft->fresh()->version, 'data' => ['id' => $employer->id]]);

        // Confirm directly with editable dates and NO 12-line manual schedule (Functional Skills left Pending)
        $this->post(route('setup68.confirm', $draft->fresh()), [
            'version' => $draft->fresh()->version,
            'confirmed' => '1',
            'practical_end' => '2029-03-20',
            'programme_end' => '2029-05-01',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $apprentice = Apprentice::where('reference', 'TT73-001')->firstOrFail();
        $plan = IndividualDeliveryPlan::where('apprentice_id', $apprentice->id)->firstOrFail();
        $this->assertSame('2029-03-20', $plan->approved['details']['practical_end_date']);
        $this->assertSame('2029-05-01', $plan->approved['details']['apprenticeship_end_date']);
        $this->assertCount(12, $plan->approved['blocks']);
        $this->assertSame('2029-03-20', $plan->approved['blocks'][9]['end']);
        $this->assertSame('2029-05-01', $plan->approved['blocks'][11]['end']);
    }
}
