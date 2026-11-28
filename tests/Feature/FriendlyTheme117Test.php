<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\User;
use App\Support\FriendlyTheme117;
use App\Support\PortalNavigation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class FriendlyTheme117Test extends TestCase
{
    use LazilyRefreshDatabase;

    #[TestWith(['admin', '/apprentices'])] #[TestWith(['ceo', '/apprentices'])] #[TestWith(['tutor', '/apprentices'])]
    #[TestWith(['apprentice', '/my-next-step'])] #[TestWith(['employer', '/employer-actions'])] #[TestWith(['mentor', '/reviews'])]
    #[TestWith(['functional_skills_tutor', '/maths-english'])] #[TestWith(['careers_adviser', '/careers'])]
    #[TestWith(['inclusion_lead', '/support-register'])] #[TestWith(['inclusion_member', '/support-register'])]
    #[TestWith(['staff', '/staff-development'])] #[TestWith(['manager', '/staff-development'])]
    public function test_role_pages_share_theme_and_keep_source_actions(string $role, string $url): void
    {
        $user = User::factory()->create(['role' => $role]);
        if ($role === 'apprentice') {
            Apprentice::factory()->create(['user_id' => $user->id]);
        }
        $this->actingAs($user)->get($url)->assertOk()->assertSee('data-portal-theme="117"', false)->assertSee('friendly-theme117.css', false)->assertSee('friendly-theme117.js', false);
    }

    public function test_menu_is_exactly_the_authorised_destination_inventory_for_every_role(): void
    {
        foreach (['admin', 'ceo', 'tutor', 'apprentice', 'employer', 'mentor', 'functional_skills_tutor', 'careers_adviser', 'inclusion_lead', 'inclusion_member', 'manager', 'staff'] as $role) {
            $u = User::factory()->create(['role' => $role]);
            $r = Request::create('/');
            $source = PortalNavigation::build($u, $r);
            $theme = FriendlyTheme117::menu($u, $r);
            $old = collect($source['primary'])->merge(collect($source['groups'])->pluck('links')->collapse())->pluck('href')->unique()->sort()->values()->all();
            $new = collect($theme['daily'])->merge(collect($theme['sections'])->pluck('links')->collapse())->pluck('href')->sort()->values()->all();
            $this->assertSame($old, $new, $role);
            $this->assertLessThanOrEqual(6, count($theme['daily']));
        }
    }

    public function test_admin_daily_work_includes_employers_and_support_without_duplicate_links(): void
    {
        $u = User::factory()->create(['role' => 'admin']);
        $menu = FriendlyTheme117::menu($u, Request::create('/'));
        $this->assertSame(['Home', 'Apprentices', 'Registers', 'Support', 'Employers', 'Reports'], collect($menu['daily'])->map(fn ($l) => FriendlyTheme117::label($l['label']))->all());
        $this->actingAs($u)->get('/apprentices')->assertOk()->assertSee('for="theme117-search"', false);
    }

    public function test_training_plan_and_account_work_remain_available_and_other_roles_stay_denied(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $a = Apprentice::factory()->create();
        Mail::fake();
        $before = DB::table('individual_delivery_plans')->count();
        $this->actingAs($admin)->get('/apprentices/'.$a->id)->assertOk()->assertSee(route('delivery.show', $a), false)->assertSee(route('apprentice-onboarding.show', $a), false);
        $this->assertSame($before, DB::table('individual_delivery_plans')->count());
        Mail::assertNothingSent();
        $other = User::factory()->create(['role' => 'apprentice']);
        $this->actingAs($other)->get('/apprentices/'.$a->id)->assertForbidden()->assertSee('friendly-theme117.css', false);
    }

    public function test_guest_signin_and_recovery_have_theme_without_internal_navigation(): void
    {
        foreach (['/login', '/forgot-password'] as $url) {
            $this->get($url)->assertOk()->assertSee('friendly-theme117.css', false)->assertDontSee('id="portal-navigation"', false);
        }
    }

    public function test_asset_order_keeps_new_tokens_last_and_print_data_private_rules_present(): void
    {
        $s = $this->get('/login')->assertOk()->getContent();
        $this->assertLessThan(strpos($s, 'friendly-theme117.css'), strpos($s, 'portal-navigation.css'));
        $css = file_get_contents(public_path('assets/friendly-theme117.css'));
        foreach (['@media print', 'prefers-reduced-motion', 'focus-visible', '[type="checkbox"]', '--gta-control-border'] as $value) {
            $this->assertStringContainsString($value,$css);
        }
        $this->assertStringNotContainsString('form{display:none',$css);
    }
}
