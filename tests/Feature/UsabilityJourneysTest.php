<?php
namespace Tests\Feature;

use App\Models\{Apprentice, SupportCapability, SupportConcern, SupportPlan, User};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class UsabilityJourneysTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_find_an_apprentice_without_support_records_even_with_empty_followup_filters(): void
    {
        $a = Apprentice::factory()->create(['reference' => 'START-SUPPORT-001']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/support?search=START-SUPPORT-001&state=overdue&plan_state=overdue')
            ->assertOk()->assertSee($a->reference)->assertSee('Start / manage support')
            ->assertSee(route('support.index', $a).'#support-markers', false)
            ->assertViewHas('supportPeople', fn ($rows) => $rows->total() === 1);
        $this->get('/apprentices/'.$a->id.'/support')->assertOk()
            ->assertSee('Record the support need first')->assertSee('Add or change markers');
        $this->assertDatabaseCount('support_concerns', 0);
        $this->assertDatabaseCount('support_plans', 0);
        $this->assertDatabaseCount('marker_change_events', 0);
    }

    public function test_support_finder_paginates_independently_and_does_not_expand_member_scope(): void
    {
        $member = User::factory()->create(['role' => 'tutor']);
        SupportCapability::create(['user_id' => $member->id, 'duty' => 'member', 'scope' => 'assigned', 'apprentice_ids' => [], 'group_ids' => []]);
        Apprentice::factory()->count(23)->create(['tutor_id' => $member->id]);
        $outside = Apprentice::factory()->create();
        $this->actingAs($member)->get('/support?apprentice_page=2&state=overdue')
            ->assertOk()->assertDontSee($outside->reference)->assertDontSee('Manage markers')
            ->assertViewHas('supportPeople', fn ($rows) => $rows->total() === 23 && count($rows->items()) === 3);
        $this->get('/support?search='.$outside->reference)->assertViewHas('supportPeople', fn ($rows) => $rows->total() === 0);
        $this->get('/apprentices/'.$outside->id.'/support')->assertForbidden();
        $this->getJson('/support?apprentice_page=0')->assertUnprocessable();
        $this->getJson('/support?apprentice_page[]=2')->assertUnprocessable();
    }

    public function test_plan_entry_guides_missing_concern_and_continues_existing_plan_without_creating_duplicates(): void
    {
        $a = Apprentice::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get('/apprentices/'.$a->id.'/support-plans/new')->assertOk()->assertSee('No classified concern is available to link');
        $c = SupportConcern::factory()->create(['apprentice_id' => $a->id]);
        $c->forceFill(['code' => 'IM36'])->save();
        $this->get('/apprentices/'.$a->id.'/support')->assertOk()->assertSee('Create support-plan draft');
        $p = SupportPlan::create(['apprentice_id' => $a->id, 'concern_ids' => [$c->id], 'draft' => ['fields' => []]]);
        $p->status = 'Draft'; $p->save();
        $this->get('/apprentices/'.$a->id.'/support')->assertOk()->assertSee('Continue current support plan')
            ->assertDontSee('Create support-plan draft')->assertSee(route('support.plan', [$a, $p]), false);
        $this->assertDatabaseCount('support_plans', 1);
    }
}
