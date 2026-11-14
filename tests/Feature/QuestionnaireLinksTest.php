<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\SupportCapability;
use App\Models\SurveyReceipt;
use App\Models\User;
use App\Support\QuestionnaireLinks;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QuestionnaireLinksTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const URL = 'https://forms.example.test/Pages/ResponsePage.aspx?id=FICTIONAL-FORM&origin=portal';

    private function actor(string $role = 'apprentice'): User
    {
        $user = User::factory()->create(['role' => $role]);
        if ($role === 'apprentice') {
            $a = Apprentice::factory()->create();
            $a->user_id = $user->id;
            $a->save();
        }

        return $user;
    }

    private function ready(string $kind = 'induction', string $url = self::URL): void
    {
        config(['questionnaires.enabled' => true, 'questionnaires.allowed_hosts' => ['forms.example.test'],
            "questionnaires.links.$kind.url" => $url, "questionnaires.links.$kind.reviewed_sha256" => hash('sha256', $url)]);
    }

    public function test_default_is_unavailable_and_no_external_destination_is_exposed(): void
    {
        $this->actingAs($this->actor())->get('/questionnaires')->assertOk()
            ->assertSee('Currently unavailable')->assertDontSee('target="_blank"', false);
        $this->assertFalse(QuestionnaireLinks::inspect()['all_ready']);
        $this->artisan('gta:questionnaire-check')->assertExitCode(1);
    }

    public function test_reviewed_link_opens_exact_shared_url_with_no_referrer_and_accessible_new_tab_notice(): void
    {
        $this->ready();
        $this->actingAs($this->actor())->get('/questionnaires')->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('href="'.e(self::URL).'"', false)->assertSee('rel="noopener noreferrer"', false)
            ->assertSee('referrerpolicy="no-referrer"', false)->assertSee('(new tab)')
            ->assertSee('Opening a link does not record submission or completion')
            ->assertSee('Currently unavailable');
    }

    public function test_changing_even_query_string_invalidates_previous_review_and_hides_new_url(): void
    {
        $this->ready();
        config(['questionnaires.links.induction.url' => self::URL.'&changed=YES']);
        $this->actingAs($this->actor())->get('/questionnaires')->assertOk()->assertDontSee('changed=YES')->assertDontSee('target="_blank"', false);
        $this->assertSame('Responder link needs review', QuestionnaireLinks::inspect()['links']['induction']['status']);
    }

    public function test_request_parameters_cannot_override_configured_destination_or_expose_a_private_receipt(): void
    {
        $this->ready();
        $this->actingAs($this->actor())->get('/questionnaires?url=https%3A%2F%2Fevil.example&apprentice=UNTRUSTED-ROW-SELECTOR&show=followup')
            ->assertOk()->assertSee(e(self::URL), false)->assertDontSee('evil.example')->assertDontSee('UNTRUSTED-ROW-SELECTOR');
    }

    public function test_disabled_flag_or_removed_host_with_existing_review_hides_destination(): void
    {
        $this->ready();
        config(['questionnaires.enabled' => false]);
        $this->assertNull(QuestionnaireLinks::cards()['induction']['url']);
        config(['questionnaires.enabled' => true, 'questionnaires.allowed_hosts' => []]);
        $this->assertNull(QuestionnaireLinks::cards()['induction']['url']);
        config(['questionnaires.allowed_hosts' => 'forms.example.test']);
        $this->assertNull(QuestionnaireLinks::cards()['induction']['url']);
    }

    public static function rejectedLinks(): array
    {
        return array_map(fn ($url) => [$url], [
            'http://forms.example.test/respond', 'javascript:alert(1)', '//forms.example.test/respond',
            'https://forms.example.test.evil.test/respond', 'https://evil.test/respond',
            'https://forms.example.test@evil.test/respond', 'https://user@forms.example.test/respond',
            'https://user:pass@forms.example.test/respond', 'https://forms.example.test:443/respond',
            'https://forms.example.test/respond#fragment', "https://forms.example.test/respond\n",
            'https://forms.example.test/%0aHeader', 'https://forms.example.test/%5cpath',
            'https://forms.example.test\\@evil.test/respond', 'https://forms.example.test/edit',
            'https://forms.example.test/Pages/DesignPageV2.aspx?id=example',
            'https://forms.example.test/forms/d/example/%65dit',
            'https://forms.example.test/forms/d/example/%2565dit',
            'https://forms.example.test/respond?mode=edit',
            'https://forms.example.test/respond?email=learner@example.test',
            'https://forms.example.test/respond?entry.123=personal',
            'https://forms.example.test/respond?prefill_name=private',
            'https://127.0.0.1/respond', 'https://localhost/respond',
            'https://forms.example.test/'.str_repeat('a', 2048),
        ]);
    }

    #[DataProvider('rejectedLinks')]
    public function test_malformed_unapproved_editor_or_personalised_links_fail_even_with_matching_hash(string $url): void
    {
        $this->ready('induction', $url);
        config(['questionnaires.allowed_hosts' => ['forms.example.test', '127.0.0.1', 'localhost']]);
        $this->assertFalse(QuestionnaireLinks::inspect()['links']['induction']['ready']);
        $this->assertNull(QuestionnaireLinks::cards()['induction']['url']);
    }

    public function test_missing_wrong_or_non_string_review_and_malformed_url_config_fail_closed(): void
    {
        $this->ready();
        foreach ([null, '', 'reviewed', str_repeat('a', 64), ['invalid']] as $hash) {
            config(['questionnaires.links.induction.reviewed_sha256' => $hash]);
            $this->assertNull(QuestionnaireLinks::cards()['induction']['url']);
        }
        config(['questionnaires.links.induction.url' => ['invalid']]);
        $this->assertNull(QuestionnaireLinks::cards()['induction']['url']);
    }

    public function test_all_three_destinations_are_independent_and_check_output_contains_no_url_or_answers(): void
    {
        foreach (['induction', 'sixweek', 'ongoing'] as $kind) {
            $this->ready($kind, 'https://forms.example.test/respond?form='.$kind);
        }
        $inspection = QuestionnaireLinks::inspect();
        $this->assertTrue($inspection['all_ready']);
        $this->assertStringNotContainsString('https://', json_encode($inspection));
        $this->assertFalse($inspection['network_requests_performed']);
        $this->assertFalse($inspection['writes_performed']);
        $this->assertFalse($inspection['external_form_verified']);
        $this->artisan('gta:questionnaire-check')->expectsOutputToContain('candidate_sha256')->assertExitCode(0);
        config(['questionnaires.links.sixweek.url' => 'https://forms.example.test/respond?form=changed']);
        $cards = QuestionnaireLinks::cards();
        $this->assertTrue($cards['induction']['ready']);
        $this->assertFalse($cards['sixweek']['ready']);
        $this->assertTrue($cards['ongoing']['ready']);
    }

    public function test_only_bound_apprentices_or_inclusion_team_can_view_and_only_managers_see_setup(): void
    {
        foreach (['apprentice', 'admin', 'ceo', 'inclusion_lead', 'inclusion_member'] as $role) {
            $this->actingAs($this->actor($role))->get('/questionnaires')->assertOk();
            $response = $this->get('/questionnaires/configuration');
            in_array($role, ['admin', 'ceo'], true) ? $response->assertOk() : $response->assertForbidden();
        }
        foreach (['employer', 'tutor', 'mentor', 'careers_adviser', 'functional_skills_tutor'] as $role) {
            $this->actingAs($this->actor($role))->get('/questionnaires')->assertForbidden();
            $this->get('/questionnaires/configuration')->assertForbidden();
        }
    }

    public function test_additional_inclusion_duty_permits_links_but_does_not_broaden_receipt_scope(): void
    {
        $user = $this->actor('tutor');
        SupportCapability::create(['user_id' => $user->id, 'duty' => 'member', 'scope' => 'assigned', 'version' => 1]);
        $a = Apprentice::factory()->create();
        $this->actingAs($user)->get('/questionnaires')->assertOk();
        $this->get('/apprentices/'.$a->id.'/surveys')->assertForbidden();
    }

    public function test_unbound_apprentice_account_is_refused_until_bound(): void
    {
        $user = User::factory()->create(['role' => 'apprentice']);
        $this->actingAs($user)->get('/questionnaires')->assertForbidden();
        $a = Apprentice::factory()->create();
        $a->user_id = $user->id;
        $a->save();
        $this->get('/questionnaires')->assertOk();
    }

    public function test_learner_page_never_discloses_receipts_or_writes_completion_checks_or_events(): void
    {
        $user = $this->actor();
        $a = Apprentice::where('user_id', $user->id)->firstOrFail();
        $receipt = SurveyReceipt::create(['apprentice_id' => $a->id, 'kind' => 'induction', 'submitted_date' => today(),
            'recorded_by' => $user->id]);
        $receipt->checks = [['private' => 'PRIVATE-CHECK-SENTINEL']];
        $receipt->save();
        $a->first_name = 'PRIVATE-LEARNER-NAME';
        $a->save();
        $before = SurveyReceipt::firstOrFail()->toArray();
        $events = DB::table('learning_events')->count();
        $this->ready();
        $this->actingAs($user)->get('/questionnaires')->assertOk()->assertDontSee('PRIVATE-CHECK-SENTINEL')
            ->assertDontSee($a->first_name)->assertDontSee('Awaiting Inclusion check');
        $this->get('/apprentices/'.$a->id.'/surveys')->assertForbidden();
        $this->post('/questionnaires', ['answers' => ['private' => 'NEVER-STORE'], 'completed' => 1])->assertStatus(405);
        $this->assertSame($before, SurveyReceipt::firstOrFail()->toArray());
        $this->assertDatabaseCount('survey_receipts', 1);
        $this->assertSame($events, DB::table('learning_events')->count());
    }

    public function test_inactive_stale_and_guest_accounts_are_refused(): void
    {
        $this->get('/questionnaires')->assertRedirect('/login');
        $user = $this->actor();
        $this->actingAs($user);
        $user->forceFill(['active' => false])->save();
        $this->get('/questionnaires')->assertForbidden();
        $user = $this->actor('admin');
        $this->actingAs($user);
        $user->increment('auth_version');
        $this->get('/questionnaires/configuration')->assertRedirect('/login');
    }
}
