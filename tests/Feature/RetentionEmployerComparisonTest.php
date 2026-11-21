<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\ApprenticeEntryContext;
use App\Models\Employer;
use App\Models\ProgrammeOutcome;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class RetentionEmployerComparisonTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_employer_comparison_uses_recorded_links_and_sourced_outcomes(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 21));
        $original = Employer::factory()->create(['name' => 'Same Workshop', 'reference' => 'EMP-ORIGINAL']);
        $later = Employer::factory()->create(['name' => 'Same Workshop', 'reference' => 'EMP-LATER']);
        $admin = User::factory()->create(['role' => 'admin']);
        $record = function ($entry, $linked, array $outcome) use ($admin): Apprentice {
            $apprentice = Apprentice::factory()->create([
                'employer_id' => $linked?->id, 'start_date' => '2025-09-01', 'planned_end_date' => '2027-09-01',
            ]);
            if ($entry) {
                ApprenticeEntryContext::create([
                    'apprentice_id' => $apprentice->id, 'entry_employer_id' => $entry->id,
                    'stage' => 'Enrolment', 'updated_by' => $admin->id,
                ]);
            }
            ProgrammeOutcome::create(['apprentice_id' => $apprentice->id, 'context' => $outcome + [
                'sourceRef' => 'Synthetic outcome record', 'endDate' => '2026-10-01',
            ]]);

            return $apprentice;
        };
        $changed = $record($original, $later, ['status' => 'Completed', 'achievement' => 'Achieved', 'achievementDate' => '2026-10-02']);
        $record($original, $original, ['status' => 'Completed', 'achievement' => 'Not achieved']);
        $record($original, $original, ['status' => 'Completed', 'achievement' => 'Awaiting result']);
        $record($later, $later, ['status' => 'Withdrawn']);
        $record(null, $later, ['status' => 'Completed', 'achievement' => 'Achieved']);

        $this->actingAs($admin);
        $entry = $this->get(route('retention.report', ['factor' => 'entry_employer']))->assertOk()
            ->assertSee('Same Workshop · EMP-ORIGINAL')->assertSee('Same Workshop · EMP-LATER')
            ->assertSee('entry_employer_id='.$original->id);
        $byEntry = $entry->viewData('groups');
        $this->assertSame(1, $byEntry['Same Workshop · EMP-ORIGINAL']['achieved']);
        $this->assertSame(1, $byEntry['Same Workshop · EMP-ORIGINAL']['not_achieved']);
        $this->assertSame(1, $byEntry['Same Workshop · EMP-ORIGINAL']['missing_results']);
        $this->assertSame(1, $byEntry['Same Workshop · EMP-LATER']['withdrawn']);
        $this->assertSame(0, $byEntry['Entry employer not recorded']['achieved']);

        $linked = $this->get(route('retention.report', [
            'factor' => 'linked_employer', 'linked_employer_id' => $later->id,
        ]))->assertOk();
        $this->assertSame(1, $linked->viewData('groups')['Same Workshop · EMP-LATER']['achieved']);
        $this->assertSame(3, $linked->viewData('rows')->total());
        $row = $linked->viewData('rows')->getCollection()->firstWhere('apprentice.id', $changed->id);
        $this->assertSame('Same Workshop · EMP-ORIGINAL', $row['entry_employer']);
        $this->assertSame('Same Workshop · EMP-LATER', $row['linked_employer']);
        $this->assertStringContainsString('Same Workshop · EMP-ORIGINAL', $this->get(route('retention.report', [
            'factor' => 'entry_employer', 'export' => 'csv',
        ]))->assertOk()->streamedContent());
    }
}
