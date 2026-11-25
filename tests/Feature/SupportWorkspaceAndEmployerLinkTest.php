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
        $this->actingAs($admin)->post(route('support.track-marker',$apprentice), ['code'=>'IM34','frequency'=>'monthly','reason'=>'Travel disruption affected attendance'])->assertSessionHasNoErrors();
        $schedule = SupportRegister71::addMarker($admin, $apprentice, ['code'=>'IM36','interval_key'=>'six_monthly','owner_id'=>$admin->id]);
        SupportRegister71::addMarker($admin, $other, ['code'=>'IM1','interval_key'=>'weekly','owner_id'=>$admin->id]);
        $page = route('support.index',$apprentice);
        $this->actingAs($member)->get(route('support.worklist'))->assertOk()
            ->assertSee('Alex Morgan')->assertDontSee($other->reference)->assertDontSee('Travel disruption affected attendance');
        $this->get($page)->assertOk()->assertSeeInOrder(['Markers and reasons', 'Travel disruption affected attendance', 'Action plan', 'SMART target'])
            ->assertSee('Support target template')->assertSee('Review outcome template')->assertSee('Resolve a transport barrier')
            ->assertSee('value="custom"', false)->assertSee('name="owner_id"', false);
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

        $target = [
            'request_uuid'=>(string) Str::uuid(),'apprentice_id'=>$apprentice->id,'owner_id'=>$member->id,
            'due_date'=>today()->subDay()->toDateString(),'summary'=>'Arrange next meeting','target_type'=>'smart',
        ];
        $this->from($page)->post(route('support-register71.add-action'), $target)->assertSessionHasErrors('measure');
        $this->from($page)->post(route('support-register71.add-action'), [
            ...$target, 'measure'=>'Acceptances from apprentice and tutor',
        ])->assertSessionHasNoErrors();
        $action = SupportSharedAction71::sole();
        $this->assertSame('Acceptances from apprentice and tutor', $action->details['measure']);
        $this->get($page)->assertOk()->assertSee('Acceptances from apprentice and tutor');
        $this->get(route('support.worklist'))->assertOk()->assertSee('Arrange next meeting');
        $this->get(route('support.report'))->assertOk()->assertSee('1 have an overdue support action')->assertDontSee('Arrange next meeting');
        $this->from($page)->post(route('support-register71.complete-action',$action), ['outcome'=>'Meeting arranged'])->assertSessionHasNoErrors();
        $this->get($page)->assertOk()->assertSee('Meeting arranged');
        $this->get(route('support.worklist'))->assertOk()->assertDontSee('Arrange next meeting');
        $this->get(route('support.report'))->assertOk()->assertSee('0 have an overdue support action');
    }

    public function test_marker_other_uses_the_same_reason_field(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $apprentice = Apprentice::factory()->create();
        $route = route('support.track-marker', $apprentice);
        $marker = ['code' => 'IM40', 'frequency' => 'monthly'];

        $this->actingAs($admin)->post($route, $marker)->assertSessionHasErrors('reason');
        $this->post($route, $marker + ['reason' => 'Temporary travel disruption'])->assertSessionHasNoErrors();
        $record = SupportConcern::sole();
        $this->assertSame('Temporary travel disruption', $record->other_code);
        $this->get(route('support.index', $apprentice))->assertOk()->assertSee('Temporary travel disruption');
    }

    public function test_employer_change_records_the_effective_date_and_keeps_other_assignments(): void
    {
        $admin = User::factory()->create(['role'=>'admin']);
        $tutor = User::factory()->create(['role'=>'tutor']);
        $oldEmployer = Employer::factory()->create();
        $newContact = User::factory()->create(['role' => 'employer', 'email' => 'new-workplace@example.test']);
        $newEmployer = Employer::factory()->create(['contact_email' => $newContact->email]);
        $person = Apprentice::factory()->create(['tutor_id'=>$tutor->id,'employer_id'=>$oldEmployer->id,'email'=>'learner@example.test']);
        $contact = User::factory()->create(['role' => 'employer']);
        $grant = \App\Support\EmployerActions::grant($admin, $admin->auth_version, $person, [
            'user_id' => $contact->id, 'version' => 0, 'identity_reference' => 'Fictional access verification',
        ]);
        $this->assertTrue(\App\Support\EmployerActions::valid($grant, $person));
        $confirm = route('apprentices.edit', ['apprentice'=>$person, 'link_employer'=>$newEmployer->id]);
        $this->actingAs($tutor)->get($confirm)->assertForbidden();
        $this->actingAs($admin)->get(route('employer68.show',['link'=>$newEmployer->id,'apprentice'=>$person->id]))->assertRedirect($confirm);
        $this->get($confirm)->assertOk()->assertSee($oldEmployer->name)->assertSee($newEmployer->name);
        $change = route('employer68.change', $person);
        $payload = ['version'=>$person->version, 'employer_id'=>$newEmployer->id,
            'employer_version'=>$newEmployer->record_version, 'effective_on'=>today()->subDay()->toDateString()];
        $this->get(route('employer68.show', ['apprentice'=>$person->id, 'search'=>$newEmployer->reference]))
            ->assertOk()->assertSee('Choose employer');
        $this->get($confirm)->assertSee('Effective date')->assertSee('Directory contact');
        $this->actingAs($tutor)->post($change, $payload)->assertForbidden();
        $this->actingAs($admin)->from($confirm)->put(route('apprentices.update',$person), [
            ...$person->only(['reference','first_name','last_name','version']), 'employer_id'=>$newEmployer->id,
        ])->assertSessionHasErrors('employer_id');
        $this->from($confirm)->post($change, $payload)
            ->assertRedirect($confirm)->assertSessionHasNoErrors();
        $this->from($confirm)->post($change, $payload)->assertSessionHasErrors('version');
        $this->assertEquals($newEmployer->id, $person->fresh()->employer_id);
        $this->assertEquals($tutor->id, $person->fresh()->tutor_id);
        $this->assertSame('learner@example.test', $person->fresh()->email);
        $this->assertDatabaseHas('assignment_change_events', ['apprentice_id'=>$person->id,'actor_id'=>$admin->id]);
        $history = \App\Models\EntryContextEvent::where('apprentice_id', $person->id)->where('kind', 'Employment')->sole();
        $this->assertSame($payload['effective_on'], $history->effective_on->toDateString());
        $this->assertEquals($newEmployer->id, $history->payload['after']['entry_employer_id']);
        $this->assertFalse($grant->fresh()->active);
        $this->assertFalse(\App\Support\EmployerActions::valid($grant->fresh(), $person->fresh()));
        $this->get($confirm)->assertOk()->assertSee('Verify workplace access')->assertSee($newContact->email);
        $this->assertDatabaseMissing('employer_apprentice_grants', ['user_id'=>$newContact->id, 'apprentice_id'=>$person->id]);
        $access = route('employer-grants.save', $person);
        $verification = [
            'user_id'=>$newContact->id, 'version'=>0, 'linked_employer_id'=>$newEmployer->id,
            'linked_employer_version'=>$newEmployer->record_version,
            'identity_reference'=>'Fictional independent authority check', 'confirmed'=>1,
        ];
        $this->actingAs($tutor)->post($access, $verification)->assertForbidden();
        $this->actingAs($admin)->from($confirm)->post($access, [...$verification, 'confirmed'=>0])
            ->assertSessionHasErrors('confirmed');
        $this->from($confirm)->post($access, [...$verification, 'user_id'=>$contact->id, 'version'=>$grant->fresh()->version])
            ->assertSessionHasErrors('actions');
        $this->from($confirm)->post($access, [...$verification, 'linked_employer_version'=>$newEmployer->record_version + 1])
            ->assertSessionHasErrors('actions');
        $this->from($confirm)->post($access, $verification)->assertRedirect($confirm)->assertSessionHasNoErrors();
        $this->get($confirm)->assertOk()->assertSee('Access verified for '.$newContact->name)->assertDontSee('Confirm workplace access');
        $newGrant = \App\Models\EmployerApprenticeGrant::where('user_id', $newContact->id)->sole();
        $this->assertTrue(\App\Support\EmployerActions::valid($newGrant, $person->fresh()));
    }
}
