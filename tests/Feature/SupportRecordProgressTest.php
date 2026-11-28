<?php

namespace Tests\Feature;

use App\Models\{Apprentice, LearningTask, SmartAction, SupportCapability, SupportSharedAction71, User};
use App\Support\SupportRegister71;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupportRecordProgressTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_single_record_shows_each_teams_actions_alongside_progress_and_scoped_training_actions(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $member = User::factory()->create(['role' => 'inclusion_member']);
        $apprentice = Apprentice::factory()->create();
        $other = Apprentice::factory()->create();
        SupportCapability::create([
            'user_id' => $member->id, 'duty' => 'member', 'scope' => 'assigned',
            'apprentice_ids' => [$apprentice->id], 'group_ids' => [],
        ]);
        $marker = SupportRegister71::addMarker($admin, $apprentice, [
            'code' => 'IM36', 'owner_id' => $admin->id,
        ])->support_concern_id;
        SupportRegister71::createAction($admin, $apprentice, [
            'owner_id' => $admin->id, 'due_date' => today()->subDay()->toDateString(),
            'summary' => 'Agree a return date', 'support_concern_id' => $marker,
        ]);
        $page = route('support.index', $apprentice);
        $input = [
            'apprentice_id' => $apprentice->id, 'owner_id' => $member->id,
            'due_date' => today()->addDay()->toDateString(), 'summary' => 'Adapt workshop instructions',
            'request_uuid' => (string) Str::uuid(), 'lead_team' => 'inclusion',
        ];
        $this->actingAs($admin)->from($page)->post(route('support-register71.add-action'), [
            ...$input, 'lead_team' => 'invalid',
        ])->assertSessionHasErrors('lead_team');
        $this->post(route('support-register71.add-action'), $input)->assertSessionHasNoErrors();
        $action = SupportSharedAction71::where('request_uuid', $input['request_uuid'])->sole();
        $this->assertSame('inclusion', $action->details['lead_team']);

        LearningTask::factory()->create(['apprentice_id' => $apprentice->id, 'block' => 1]);
        LearningTask::factory()->create(['apprentice_id' => $apprentice->id, 'block' => 1, 'reference' => 'b1-t4', 'status' => 'Approved']);
        SmartAction::factory()->create(['apprentice_id' => $apprentice->id, 'title' => 'Fictional planned training target']);
        SmartAction::factory()->create([
            'apprentice_id' => $apprentice->id, 'title' => 'Fictional completed training target', 'status' => 'Completed',
        ]);
        SmartAction::factory()->create(['apprentice_id' => $other->id, 'title' => 'Out-of-scope training target']);

        $this->get($page)->assertOk()
            ->assertSeeInOrder(['Progress at a glance', '1 of 2 tasks approved', 'Agree a return date', 'Marketing ·',
                'Adapt workshop instructions', 'Inclusion ·', 'Show other actions', 'Fictional planned training target',
                'Fictional completed training target'])
            ->assertDontSee('Out-of-scope training target');
        $this->actingAs($member)->get($page)->assertOk()->assertSee('Adapt workshop instructions')
            ->assertDontSee('Fictional planned training target');
        $this->get(route('support.index', $other))->assertForbidden();
        $this->from($page)->post(route('support-register71.complete-action', $action), ['outcome' => 'Instructions adapted'])
            ->assertSessionHasNoErrors();
        $this->get($page)->assertOk()->assertSeeInOrder(['Support history', 'Adapt workshop instructions', 'Instructions adapted']);
    }
}
