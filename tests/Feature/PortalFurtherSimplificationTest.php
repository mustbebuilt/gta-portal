<?php

namespace Tests\Feature;

use App\Models\{Apprentice, User};
use App\Support\PortalNavigation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class PortalFurtherSimplificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function document(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        $dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        return new \DOMXPath($dom);
    }

    public function test_profile_common_destinations_are_present_once_and_reading_keeps_history_unchanged(): void
    {
        $admin = User::factory()->create(['role'=>'admin']);
        $person = Apprentice::factory()->create();
        $version = $person->version;
        $html = $this->actingAs($admin)->get(route('apprentices.show', $person))->assertOk()->getContent();
        $xpath = $this->document($html);
        foreach (['learning.index','attendance.apprentice','reviews.create','apprentices.edit','apprentices.close-down'] as $route) {
            $href = route($route, $person);
            $this->assertSame(1, $xpath->query('//main//a[@href="'.$href.'"]')->length, $route.' repeats on the record');
        }
        $this->assertSame(0, $xpath->query('//details[contains(@class,"action-disclosure64")]//details[contains(@class,"action-disclosure64")]')->length);
        $this->assertSame($version, $person->fresh()->version);
        $this->get(route('delivery.show', $person))->assertOk();
    }

    public function test_creation_actions_remain_on_normal_start_pages_without_repeating_in_sidebar(): void
    {
        $admin = User::factory()->create(['role'=>'admin']);
        $this->actingAs($admin);
        $menu = PortalNavigation::build($admin, Request::create('/dashboard'));
        $labels = array_column($menu['primary'], 'label');
        $this->assertNotContains('Add apprentice', $labels);
        $this->assertNotContains('Add or link employer', $labels);
        $this->assertContains('Apprentices', $labels);
        $this->get(route('dashboard.index'))->assertOk()->assertSee(route('setup68.create'))->assertSee(route('employer68.show'));
        $this->get(route('apprentices.index'))->assertOk()->assertSee(route('setup68.create'));
        $this->get(route('directories.index','employers'))->assertOk()->assertSee(route('employer68.show'));
        $this->actingAs(User::factory()->create(['role'=>'apprentice']));
        $this->get(route('setup68.index'))->assertForbidden();
        $this->get(route('employer68.show'))->assertForbidden();
    }

    public function test_chart_display_has_one_selector_and_retains_exact_tables_without_javascript(): void
    {
        $this->actingAs(User::factory()->create(['role'=>'admin']));
        $html = $this->get(route('dashboard.index'))->assertOk()->getContent();
        $xpath = $this->document($html);
        $this->assertSame(1, $xpath->query('//select[@data-visual-mode]')->length);
        $this->assertSame(3, $xpath->query('//select[@data-visual-mode]/option')->length);
        $this->assertSame(0, $xpath->query('//button[@data-visual-choice]')->length);
        $this->assertSame(2, $xpath->query('//div[@data-visual-table]/table')->length);
        $this->assertSame(1, $xpath->query('//section[@aria-label="Allocated task summary"]')->length);
        $this->assertSame(0, $xpath->query('//section/h2[text()="Training task approval"]')->length);
    }
}
