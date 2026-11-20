<?php
namespace Tests\Feature;

use App\Models\{Apprentice,User};
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
}
