<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PortalSetupTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_named_owner_guide_has_real_staff_assignment_links_and_no_account_creation_side_effect(): void
    {
        $owner=User::factory()->create(['name'=>'Fictional Owner','role'=>'ceo']);
        $this->actingAs($owner)->get('/portal-setup')->assertOk()->assertSee('Fictional Owner')
            ->assertSee('ordinary Staff screen does not create')->assertSee('one primary role per account')
            ->assertSee('Saving a staff record',false)->assertSee(route('directories.index','staff'),false)
            ->assertSee(route('apprentices.index'),false)->assertSee(route('onboarding.index'),false);
        $this->assertDatabaseCount('users',1);$this->assertDatabaseCount('directory_events',0);
    }

    public function test_setup_is_admin_only_and_voice_choice_is_available_to_all_help_roles_without_autoplay(): void
    {
        foreach(['admin','ceo']as$role){$u=User::factory()->create(['role'=>$role]);$this->actingAs($u)->get('/portal-setup')->assertOk();}
        foreach(['tutor','apprentice','employer','careers_adviser','manager']as$role){
            $u=User::factory()->create(['role'=>$role]);$this->actingAs($u)->get('/portal-setup')->assertForbidden();
            $this->get('/help')->assertOk()->assertSee('Read-aloud voice')->assertSee('Nothing is read automatically')
                ->assertDontSee('Set up your account, staff and assignments');
        }
    }
}
