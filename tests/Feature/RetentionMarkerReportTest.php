<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\ProgrammeOutcome;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RetentionMarkerReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_report_includes_direct_markers_and_reconstructs_overlapping_periods_up_to_exit(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 21));
        $admin = User::factory()->create(['role' => 'admin']);
        $apprentice = Apprentice::factory()->create([
            'start_date' => '2026-09-01',
            'planned_end_date' => '2026-12-01',
        ]);
        ProgrammeOutcome::create([
            'apprentice_id' => $apprentice->id,
            'context' => ['status' => 'Withdrawn', 'endDate' => '2026-11-11', 'sourceRef' => 'Recorded withdrawal'],
        ]);
        $change = function (string $date, array $before, array $after) use ($apprentice, $admin): void {
            DB::table('marker_change_events')->insert([
                'apprentice_id' => $apprentice->id, 'actor_id' => $admin->id, 'source' => 'manual_selection',
                'before_codes' => json_encode($before), 'after_codes' => json_encode($after),
                'reason' => 'Private marker note', 'created_at' => $date,
            ]);
        };
        $change('2026-11-01', [], ['IM1', 'IM34']);
        $change('2026-11-03', ['IM1', 'IM34'], ['IM1', 'IM34', 'IM36']);
        $change('2026-11-05', ['IM1', 'IM34', 'IM36'], ['IM1', 'IM36']);
        $change('2026-11-06', ['IM1', 'IM36'], ['IM1']);
        $change('2026-11-08', ['IM1'], ['IM1', 'IM34']);
        $change('2026-11-10', ['IM1', 'IM34'], ['IM34']);
        $change('2026-11-15', ['IM34'], ['IM39']);
        DB::table('apprentice_markers')->insert([
            'apprentice_id' => $apprentice->id, 'recorded_by' => $admin->id,
            'code' => 'IM39', 'created_at' => '2026-11-15', 'updated_at' => '2026-11-15',
        ]);

        foreach (['Completed', 'Withdrawn', 'Active'] as $status) {
            $other = Apprentice::factory()->create(['start_date' => '2026-09-01', 'planned_end_date' => null]);
            ProgrammeOutcome::create([
                'apprentice_id' => $other->id,
                'context' => ['status' => $status, 'sourceRef' => 'Recorded outcome', 'endDate' => $status === 'Active' ? null : '2026-11-11'],
            ]);
            DB::table('apprentice_markers')->insert([
                'apprentice_id' => $other->id, 'recorded_by' => $admin->id,
                'code' => 'IM36', 'created_at' => '2026-11-01', 'updated_at' => '2026-11-01',
            ]);
        }

        $response = $this->actingAs($admin)->get(route('retention.report'))->assertOk()
            ->assertSee('Apprentice marker trends')->assertSee('IM34')->assertSee('IM36')->assertSee('Dyslexia')
            ->assertDontSee('Private marker note')->assertDontSee('IM39');
        $summary = $response->viewData('rows')->getCollection()->firstWhere('apprentice.id', $apprentice->id)['marker_summary'];
        $this->assertSame(2, $summary['retention_count']);
        $this->assertSame(2, $summary['retention_occasions']);
        $this->assertSame(9, $summary['retention_days']);
        $this->assertSame(6, $summary['records']);
        $this->assertSame(5, $summary['retention_records']);
        $this->assertSame(['occasions' => 2, 'days' => 8, 'records' => 3], $summary['markers']['IM34']);
        $trend = $response->viewData('markerTrends')['IM36'];
        $this->assertSame(4, $trend['people']);
        $this->assertSame(1, $trend['early_leavers']);
        $this->assertSame(2, $trend['known_outcomes']);
    }

    public function test_report_identifies_incomplete_history_and_recognises_dated_current_markers(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 21));
        $admin = User::factory()->create(['role' => 'admin']);
        $legacy = Apprentice::factory()->create();
        $current = Apprentice::factory()->create();
        DB::table('marker_change_events')->insert([
            'apprentice_id' => $legacy->id, 'actor_id' => $admin->id, 'source' => 'manual_selection',
            'before_codes' => '["IM34"]', 'after_codes' => '[]', 'created_at' => '2026-11-20',
        ]);
        DB::table('apprentice_markers')->insert([
            'apprentice_id' => $current->id, 'recorded_by' => $admin->id,
            'code' => 'IM36', 'created_at' => '2026-11-18', 'updated_at' => '2026-11-18',
        ]);

        $response = $this->actingAs($admin)->get(route('retention.report'))->assertOk()
            ->assertSee('Some retention markers were already present');
        $rows = $response->viewData('rows')->getCollection();
        $this->assertTrue($rows->firstWhere('apprentice.id', $legacy->id)['marker_summary']['incomplete']);
        $this->assertSame(4, $rows->firstWhere('apprentice.id', $current->id)['marker_summary']['retention_days']);
        $this->actingAs(User::factory()->create(['role' => 'tutor']))->get(route('retention.report'))->assertForbidden();
    }
}
