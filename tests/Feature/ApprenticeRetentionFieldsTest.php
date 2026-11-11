<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Apprentice;
use App\Models\Standard;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApprenticeRetentionFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_apprentice_retention_and_outcome_fields_can_be_stored_and_cast(): void
    {
        $user = User::factory()->create(['role' => UserRole::Apprentice]);
        $standard = Standard::create([
            'standard_code' => 'ST0482',
            'title' => 'Autocare Technician',
            'total_otj_target' => 663.00,
        ]);

        $apprentice = Apprentice::create([
            'user_id' => $user->id,
            'standard_id' => $standard->standard_id,
            'start_date' => Carbon::parse('2025-09-01'),
            'planned_end_date' => Carbon::parse('2027-03-01'),
            'status' => 'Completed',
            'achievement_status' => 'Achieved',
            'achievement_date' => Carbon::parse('2026-10-15'),
            'withdrawal_reason' => null,
            'withdrawal_reason_basis' => null,
            'apprentice_voice' => 'Apprentice confirmed',
            'outcome_source_ref' => 'EPA-2026-GTA-402',
            'destination_type' => 'Employed in sector (same employer)',
            'destination_date' => Carbon::parse('2026-11-01'),
            'destination_basis' => 'Confirmed by employer',
            'destination_ref' => 'EMP-VERIFY-1102',
            'employer_headcount_band' => '10–49',
            'headcount_reference_date' => Carbon::parse('2025-09-01'),
            'additional_needs_recorded' => 'No',
            'ehcp_recorded' => 'No',
            'context_source_ref' => 'ILR-R14-2026',
        ]);

        $this->assertDatabaseHas('apprentice', [
            'apprentice_id' => $apprentice->apprentice_id,
            'achievement_status' => 'Achieved',
            'destination_type' => 'Employed in sector (same employer)',
            'employer_headcount_band' => '10–49',
            'outcome_source_ref' => 'EPA-2026-GTA-402',
        ]);

        $fresh = $apprentice->fresh();
        $this->assertInstanceOf(Carbon::class, $fresh->achievement_date);
        $this->assertEquals('2026-10-15', $fresh->achievement_date->toDateString());
        $this->assertEquals('10–49', $fresh->employer_headcount_band);
    }
}

