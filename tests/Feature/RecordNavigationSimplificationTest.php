<?php
namespace Tests\Feature;

use App\Models\{Apprentice,Programme,TrainingSession,TutorGroup,User};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/** Staging checks supplied for the inspected conventions. Not executed in this export. */
class RecordNavigationSimplificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role): User
    {
        $user=User::factory()->create(); $user->role=$role; $user->save(); return $user;
    }

    public function test_list_has_direct_progress_and_record_links(): void
    {
        $a=Apprentice::factory()->create(); $this->actingAs($this->actor('admin'));
        $this->get(route('apprentices.index'))->assertOk()
            ->assertSee(route('progress66.index',['apprentice'=>$a->id]))
            ->assertSee(route('apprentices.show',$a))->assertSee('View progress')->assertSee('Open record');
    }

    public function test_profile_names_the_apprentice_before_common_actions(): void
    {
        $a=Apprentice::factory()->create(); $this->actingAs($this->actor('admin'));
        $response=$this->get(route('apprentices.show',$a))->assertOk()->assertSee('More record options');
        $html=$response->getContent();
        $this->assertLessThan(strpos($html,'Common apprentice actions'),strpos($html,'<h1>'));
        $response->assertSee(route('plans.show',$a))->assertSee(route('learning.index',$a));
    }

    public function test_tutor_cannot_open_an_unassigned_record_or_its_progress(): void
    {
        $t=$this->actor('tutor'); $other=$this->actor('tutor');
        $a=Apprentice::factory()->create(['tutor_id'=>$t->id]);
        $b=Apprentice::factory()->create(['tutor_id'=>$other->id]); $this->actingAs($t);
        $this->get(route('apprentices.index'))->assertOk()->assertSee($a->reference)->assertDontSee($b->reference);
        $this->assertContains($this->get(route('apprentices.show',$b))->status(),[403,404]);
        $this->assertContains($this->get(route('progress66.index',['apprentice'=>$b->id]))->status(),[403,404]);
    }

    public function test_reading_record_does_not_change_its_version(): void
    {
        $a=Apprentice::factory()->create();$version=$a->version;$this->actingAs($this->actor('admin'));
        $this->get(route('apprentices.show',$a))->assertOk();$this->get(route('apprentices.index'))->assertOk();
        $this->assertSame($version,$a->fresh()->version);
    }

    public function test_directory_searches_full_names_and_combines_scoped_filters(): void
    {
        $admin = $this->actor('admin');
        $tutor = $this->actor('tutor');
        $other = $this->actor('tutor');
        $group = TutorGroup::factory()->create(['name' => 'Fictional Monday class']);
        $hiddenGroup = TutorGroup::factory()->create(['name' => 'Fictional private class']);
        $programme = Programme::factory()->create(['name' => 'Fictional workshop programme']);
        $person = Apprentice::factory()->create([
            'first_name' => 'Fictional Alex', 'last_name' => 'Morgan',
            'tutor_group_id' => $group->id, 'programme_id' => $programme->id, 'tutor_id' => $tutor->id,
        ]);
        $hidden = Apprentice::factory()->create([
            'first_name' => 'Fictional Alex', 'last_name' => 'Morgan',
            'tutor_group_id' => $hiddenGroup->id, 'tutor_id' => $other->id,
        ]);
        $filters = ['search' => 'Alex Morgan', 'group' => $group->id, 'programme' => $programme->id, 'tutor' => $tutor->id];
        $url = route('apprentices.index', $filters);
        $recordLink = route('apprentices.show', ['apprentice' => $person, 'list' => $filters]);
        $progressLink = route('progress66.index', ['apprentice' => $person->id, 'list' => $filters]);
        $this->actingAs($admin)->get($url)->assertOk()->assertSee($person->reference)->assertDontSee($hidden->reference)
            ->assertSee($recordLink)->assertSee($progressLink);
        $this->get($recordLink)->assertOk()->assertSee($url)->assertSee('Recorded OTJ')
            ->assertSee(route('em.index', $person))->assertSee(route('actions.index', $person));
        $this->get($progressLink)->assertOk()->assertSee($url)->assertSee('Back to apprentices');

        $this->actingAs($tutor)->get(route('apprentices.index', ['group' => $hiddenGroup->id]))->assertOk()
            ->assertSee('Selection unavailable')->assertDontSee($hiddenGroup->name)->assertDontSee($hidden->reference);
        $this->getJson('/apprentices?search[]=Alex')->assertUnprocessable();
        $this->get(route('apprentices.show', ['apprentice' => $hidden, 'list' => ['search' => 'Morgan']]))->assertForbidden();
    }

    public function test_record_returns_to_actual_page_after_an_outdated_page_number(): void
    {
        Apprentice::factory()->count(16)->create(['last_name' => 'FictionalPaged']);
        $this->actingAs($this->actor('admin'));
        $response = $this->get(route('apprentices.index', ['search' => 'FictionalPaged', 'page' => 90]))
            ->assertOk()->assertSee('Page 2 of 2');
        $person = $response->viewData('records')->first();
        $filters = ['search' => 'FictionalPaged', 'page' => 2];
        $link = route('apprentices.show', ['apprentice' => $person, 'list' => $filters]);
        $response->assertSee($link);
        $this->get($link)->assertOk()->assertSee(route('apprentices.index', $filters));
    }

    public function test_record_overview_shows_the_same_confirmed_otj_hours_as_the_register_ledger(): void
    {
        $apprentice = Apprentice::factory()->create();
        $session = TrainingSession::factory()->create(['session_date' => today()->subDay(), 'members' => [$apprentice->id]]);
        $this->actingAs($this->actor('admin'))->post(route('attendance.save', $session), [
            'version' => $session->version,
            'delivered_learning' => 'Fictional practical inspection',
            'marks' => [$apprentice->id => ['code' => 'O', 'arrival' => '09:00', 'departure' => '16:00', 'otj_confirmed' => 1]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('apprentices.show', $apprentice))->assertOk()
            ->assertSee('Recorded OTJ')->assertSee('6.50 hours')->assertSee(route('otj.show', $apprentice));
        $this->get(route('otj.show', $apprentice))->assertOk()->assertSee('6.50 hours');
    }
}
