<?php

namespace Tests\Feature;

use App\Models\TimetablePlan as Plan;
use App\Models\User;
use App\Support\AnnualTimetable as A;
use App\Support\CentrePlanning as P;
use App\Support\HelpCatalogue;
use App\Support\ReviewedImports as H;
use App\Support\TimetableOptimiser as O;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TimetableUsability54Test extends TestCase
{
    use LazilyRefreshDatabase;

    private function f(): array
    {
        $this->travelTo(now()->setDate(2026, 11, 15)->setTime(10, 0));
        $u = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $dates = ['2026-11-16', '2026-11-17'];
        P::windows($u, 1, $staff, $dates, H::hash(P::windowSource($staff, $dates)), true, '09:00', '17:00');
        $args = ['staff_id' => $staff->id, 'date' => $dates[0], 'weeks' => 1, 'start' => '09:00', 'end' => '10:00', 'kind' => 'Admin', 'site' => 'Remote', 'room_id' => null, 'group_id' => null, 'headcount' => null, 'title' => 'Fictional draggable workload'];
        $p = P::save($u, 1, null, 0, (string) Str::uuid(), $args);
        P::check($u, 1, $p, 1, true);
        P::publish($u, 1, $p->refresh(), 2);

        return compact('u', 'staff', 'dates', 'args', 'p');
    }

    public function test_admin_board_has_add_workload_per_day_optional_drag_and_non_drag_route(): void
    {
        $f = $this->f();
        $this->actingAs($f['u'])->get('/centre-planning?week=2026-11-16&mode=staff')->assertOk()->assertSee('Add workload')->assertSee('Enable optional drag-and-drop shortcuts')->assertSee('data-workload-target', false)->assertSee('data-move-workload', false)->assertSee('Move workload')->assertSee('timetable-workloads54.js', false);
    }

    public function test_shared_staff_view_does_not_expose_planning_or_drag_controls(): void
    {
        $f = $this->f();
        $this->actingAs($f['staff'])->get('/centre-planning?week=2026-11-16&scope=staff')->assertOk()->assertDontSee('data-workload-tools', false)->assertDontSee('data-workload-target', false)->assertDontSee('data-move-workload', false);
    }

    public function test_add_workload_prefills_target_staff_and_date_without_creating_a_plan(): void
    {
        $f = $this->f();
        $before = Plan::count();
        $this->actingAs($f['u'])->get('/centre-planning/plans/new?staff_id='.$f['staff']->id.'&date=2026-11-17&kind=Preparation')->assertOk()->assertSee('value="2026-11-17"', false)->assertSee('Check and publish the draft')->assertSee('Preparation');
        $this->assertSame($before, Plan::count());
    }

    public function test_exact_drag_request_can_move_to_a_day_even_when_it_is_not_an_optimisation(): void
    {
        $f = $this->f();
        $d = ['from' => '2026-11-16', 'to' => '2026-11-17', 'plans' => [$f['p']->id], 'date_mode' => 'year', 'pins' => [$f['p']->id => ['date' => '2026-11-17', 'staff_id' => $f['staff']->id, 'start' => '13:00']]];
        $v = A::preview($f['u'], $d);
        $this->assertTrue($v['can_apply']);
        $this->assertSame('2026-11-17', $v['suggestion'][$f['p']->id]['after']['date']);
        $this->assertSame('13:00', $v['suggestion'][$f['p']->id]['after']['start']);
        $r = A::prepare($f['u'], 1, $d, $v['source_hash'], (string) Str::uuid(), 'Fictional requested drag move');
        $out = O::apply($f['u'], 1, $r, 1, $r->details_hash, true);
        $p = Plan::findOrFail($out->receipt['replacements'][$f['p']->id]);
        $this->assertSame('2026-11-17', $p->source['data']['args']['date']);
        $this->assertSame('14:00', $p->source['data']['args']['end']);
    }

    public function test_drag_destination_get_only_opens_an_editable_request_form(): void
    {
        $f = $this->f();
        $this->actingAs($f['u'])->get('/centre-planning/year/move/'.$f['p']->id.'?date=2026-11-17&staff_id='.$f['staff']->id)->assertOk()->assertSee('Check this exact move')->assertSee('timetable has not changed');
        $this->assertSame('Published', $f['p']->fresh()->state);
        $this->assertDatabaseCount('timetable_optimisation_runs', 0);
    }

    public function test_conflicting_pinned_move_cannot_be_saved_or_auto_replaced_by_another_slot(): void
    {
        $f = $this->f();
        $args = [...$f['args'], 'date' => '2026-11-17'];
        $p = P::save($f['u'], 1, null, 0, (string) Str::uuid(), $args);
        P::check($f['u'], 1, $p, 1, true);
        P::publish($f['u'], 1, $p->refresh(), 2);
        $v = A::preview($f['u'], ['from' => '2026-11-16', 'to' => '2026-11-17', 'plans' => [$f['p']->id], 'date_mode' => 'year', 'pins' => [$f['p']->id => ['date' => '2026-11-17', 'staff_id' => $f['staff']->id, 'start' => '09:00']]]);
        $this->assertFalse($v['can_apply']);
        $this->assertSame([], $v['suggestion']);
    }

    public function test_how_to_guides_are_current_contextual_and_role_scoped(): void
    {
        $f = $this->f();
        $this->actingAs($f['u'])->get('/help?category=Timetable')->assertOk()->assertSee('Adding a workload to the timetable')->assertSee('Save draft')->assertSee('53 weekly dates')->assertSee('drag-and-drop')->assertSee('128 plans');
        $this->get('/centre-planning/year')->assertOk()->assertSee(route('help.index'), false);
        $this->get('/centre-planning/plans/new')->assertOk()->assertSee(route('help.index'),false);
        $this->actingAs($f['staff'])->get('/help?category=Timetable')->assertNotFound();
        $this->assertArrayNotHasKey('timetable-add',HelpCatalogue::visible($f['staff']));
    }
}
