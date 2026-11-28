<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\SupportCapability;
use App\Models\SupportConcern;
use App\Models\SupportPlan;
use App\Models\TutorGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SupportReportFocusTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_headlines_count_people_and_open_markers_once_and_preserve_scope_in_drill_downs(): void
    {
        $member = User::factory()->create(['role' => 'inclusion_member']);
        $group = TutorGroup::factory()->create(['name' => 'Fictional workshop group']);
        $unassigned = TutorGroup::factory()->create(['name' => 'Outside scope']);
        $apprentice = Apprentice::factory()->create(['tutor_group_id' => $group->id]);
        Apprentice::factory()->create(['tutor_group_id' => $group->id]);
        $other = Apprentice::factory()->create(['tutor_group_id' => $unassigned->id]);
        SupportCapability::create(['user_id' => $member->id, 'duty' => 'member', 'scope' => 'assigned',
            'apprentice_ids' => [], 'group_ids' => [$group->id]]);
        foreach (['IM34', 'IM34', 'IM36'] as $code) {
            SupportConcern::factory()->create([
                'apprentice_id' => $apprentice->id, 'code' => $code, 'status' => 'New',
                'next_action' => 'Private action text', 'action_due' => today()->subDay(),
            ]);
        }
        SupportConcern::factory()->create(['apprentice_id' => $other->id, 'status' => 'New']);

        $filters = ['group' => $group->id, 'by' => today()->addDay()->toDateString()];
        $this->actingAs($member)->get(route('support.report', $filters))->assertOk()
            ->assertViewHas('registerCount', 1)
            ->assertViewHas('markers', fn ($rows) => $rows->all() === ['IM34' => 1, 'IM36' => 1])
            ->assertViewHas('summary', fn ($counts) => $counts['needs-review'] === 1 && $counts['overdue-actions'] === 1)
            ->assertSee(route('support.worklist', [...$filters, 'view' => 'overdue-actions']))
            ->assertDontSee('Private action text')->assertDontSee('Outside scope');
        $this->get(route('support.report', ['group' => $unassigned->id]))->assertOk()
            ->assertViewHas('registerCount', 0)->assertDontSee('Outside scope');
        $this->get(route('support.worklist', [...$filters, 'view' => 'overdue-actions']))->assertOk()
            ->assertSee(route('support.index', $apprentice).'#support-actions')
            ->assertDontSee(route('support.index', $other))->assertDontSee('Start meeting');
    }

    public function test_support_activity_uses_dated_records_and_keeps_private_notes_out_of_the_report(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $person = Apprentice::factory()->create();
        $plan = SupportPlan::create(['apprentice_id' => $person->id, 'concern_ids' => [], 'draft' => []]);
        $plan->forceFill([
            'status' => 'Active',
            'activities' => [
                ['date' => today()->toDateString(), 'delivered' => 'Private delivery narrative'],
                ['date' => today()->subDays(27)->toDateString()],
                ['date' => today()->subDays(28)->toDateString()],
            ],
            'reviews' => [
                ['date' => today()->toDateString(), 'direction' => 'adjust_support', 'impact' => 'Private review narrative'],
                ['date' => today()->subDay()->toDateString()],
                ['date' => 'invalid', 'direction' => 'escalate'],
            ],
        ])->save();

        $this->actingAs($admin)->get(route('support.report'))->assertOk()
            ->assertViewHas('registerCount', 1)
            ->assertViewHas('delivery', fn ($delivery) => $delivery['activities'] === 2 &&
                $delivery['apprentices'] === 1 && $delivery['reviews'] === 2 &&
                $delivery['decisions']['Adjust support'] === 1 && $delivery['decisions']['Decision not recorded'] === 1)
            ->assertSeeInOrder(['Who needs attention', 'Exact figures', 'Open markers', 'Support delivered and reviewed'])
            ->assertDontSee('Private delivery narrative')->assertDontSee('Private review narrative');
        $this->actingAs(User::factory()->create(['role' => 'tutor']))->get(route('support.report'))->assertForbidden();
    }
}
