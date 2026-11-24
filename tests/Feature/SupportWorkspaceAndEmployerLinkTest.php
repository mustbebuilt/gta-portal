<?php

namespace Tests\Feature;

use App\Models\{Apprentice, Employer, SupportCapability, SupportConcern, SupportSharedAction71, User};
use App\Support\SupportRegister71;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupportWorkspaceAndEmployerLinkTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_shared_register_respects_scope_and_retains_independent_marker_and_action_history(): void
    {
        $admin = User::factory()->create(['role'=>'admin']);
        $member = User::factory()->create(['role'=>'inclusion_member']);
        $apprentice = Apprentice::factory()->create(['first_name'=>'Alex', 'last_name'=>'Morgan']);
        $other = Apprentice::factory()->create();
        SupportCapability::create(['user_id'=>$member->id, 'duty'=>'member', 'scope'=>'assigned',
            'apprentice_ids'=>[$apprentice->id], 'group_ids'=>[]]);
        $this->actingAs($admin)->post(route('support.track-marker',$apprentice), ['code'=>'IM34','frequency'=>'monthly'])->assertSessionHasNoErrors();
        $schedule = SupportRegister71::addMarker($admin, $apprentice, ['code'=>'IM36','interval_key'=>'six_monthly','owner_id'=>$admin->id]);
        SupportRegister71::addMarker($admin, $other, ['code'=>'IM1','interval_key'=>'weekly','owner_id'=>$admin->id]);
        $page = route('support.index',$apprentice);
        $this->actingAs($member)->get(route('support.worklist'))->assertOk()
            ->assertSee('Alex Morgan')->assertDontSee($other->reference);
        $this->get(route('support-register71.index',['view'=>'all']))->assertOk()->assertDontSee($other->reference);
        $this->get(route('support.index',$other))->assertForbidden();

        $this->from($page)->post(route('support-register71.review',$schedule), [
            'version'=>$schedule->version,'kind'=>'formal_review','review_date'=>today()->toDateString(),
            'note'=>'Private outcome from scheduled review',
        ])->assertSessionHasNoErrors()->assertRedirect($page);
        $this->assertSame(SupportRegister71::addInterval(today()->toDateString(),'six_monthly'), $schedule->refresh()->next_due_date->toDateString());
        $due = $this->get(route('support.worklist',['search'=>'Alex Morgan']))->assertOk()->assertDontSee('Private outcome from scheduled review');
        $this->assertSame(['IM34'], $due->viewData('rows')->items()[0]['due']->pluck('code')->all());
        $this->assertSame(2, SupportConcern::where('apprentice_id',$apprentice->id)->where('status','!=','Closed')->count());

        $this->from($page)->post(route('support-register71.add-action'), [
            'request_uuid'=>(string) Str::uuid(),'apprentice_id'=>$apprentice->id,'owner_id'=>$member->id,
            'due_date'=>today()->subDay()->toDateString(),'summary'=>'Arrange next meeting',
        ])->assertSessionHasNoErrors();
        $action = SupportSharedAction71::firstOrFail();
        $this->get(route('support.worklist'))->assertOk()->assertSee('Arrange next meeting');
        $this->get(route('support.report'))->assertOk()->assertSee('1 have an overdue support action')->assertDontSee('Arrange next meeting');
        $this->from($page)->post(route('support-register71.complete-action',$action), ['outcome'=>'Meeting arranged'])->assertSessionHasNoErrors();
        $this->get($page)->assertOk()->assertSee('Meeting arranged');
        $this->get(route('support.worklist'))->assertOk()->assertDontSee('Arrange next meeting');
        $this->get(route('support.report'))->assertOk()->assertSee('0 have an overdue support action');
    }

    public function test_employer_link_uses_the_normal_versioned_update_and_keeps_other_assignments(): void
    {
        $admin = User::factory()->create(['role'=>'admin']);
        $tutor = User::factory()->create(['role'=>'tutor']);
        $oldEmployer = Employer::factory()->create();
        $newEmployer = Employer::factory()->create();
        $person = Apprentice::factory()->create(['tutor_id'=>$tutor->id,'employer_id'=>$oldEmployer->id,'email'=>'learner@example.test']);
        $confirm = route('apprentices.edit', ['apprentice'=>$person, 'link_employer'=>$newEmployer->id]);
        $this->actingAs($tutor)->get($confirm)->assertForbidden();
        $this->actingAs($admin)->get(route('employer68.show',['link'=>$newEmployer->id,'apprentice'=>$person->id]))->assertRedirect($confirm);
        $this->get($confirm)->assertOk()->assertSee($oldEmployer->name)->assertSee($newEmployer->name);
        $this->from($confirm)->put(route('apprentices.update',$person), [
            ...$person->only(['reference','first_name','last_name','version']), 'employer_id'=>$newEmployer->id,
        ])->assertSessionHasNoErrors();
        $this->assertEquals($newEmployer->id, $person->fresh()->employer_id);
        $this->assertEquals($tutor->id, $person->fresh()->tutor_id);
        $this->assertSame('learner@example.test', $person->fresh()->email);
        $this->assertDatabaseHas('assignment_change_events', ['apprentice_id'=>$person->id,'actor_id'=>$admin->id]);
    }
}
