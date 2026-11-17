<?php

namespace Tests\Feature;

use App\Models\AccountLifecycleState;
use App\Models\Apprentice;
use App\Models\Employer;
use App\Models\StaffDuty;
use App\Models\User;
use App\Support\EmployerActions;
use App\Support\EmployerResourceAccess;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class EmployerResources65Test extends TestCase
{
    use LazilyRefreshDatabase;

    private function content(): array
    {
        return json_decode(file_get_contents(resource_path('data/employer-resources65.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function grantedEmployer(?Employer $employer = null): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $contact = User::factory()->create(['role' => 'employer']);
        $employer ??= Employer::factory()->create();
        $apprentice = Apprentice::factory()->create(['employer_id' => $employer->id]);
        $grant = EmployerActions::grant($admin, $admin->auth_version, $apprentice, [
            'user_id' => $contact->id,
            'version' => 0,
            'identity_reference' => 'Fictional independent authority reference',
        ]);

        return [$admin, $contact, $employer, $apprentice, $grant];
    }

    private function savePage(string $courseId, array $page, string $version = '65.1', array $overrides = []): TestResponse
    {
        $answers = array_map(fn (array $q) => $q['correct'], $page['questions']);
        $final = (bool) $page['final'];
        $payload = array_merge([
            'course' => $courseId,
            'page' => $page['id'],
            'version' => $version,
            'answers' => $answers,
            'read' => ! $final,
            'commitment' => $final,
            'declarations' => $final ? array_fill(0, count($page['declarations']), true) : [],
        ], $overrides);

        return $this->postJson(route('employer-resources.save'), $payload);
    }

    public function test_unauthenticated_and_unauthorised_roles_are_denied(): void
    {
        $this->get(route('employer-resources.index'))->assertRedirect(route('login'));
        $this->getJson(route('employer-resources.state'))->assertUnauthorized();
        $this->postJson(route('employer-resources.save'), [])->assertUnauthorized();

        foreach (['apprentice', 'tutor', 'functional_skills_tutor', 'careers_adviser', 'staff', 'manager', 'unassigned'] as $role) {
            $user = User::factory()->create([
                'role' => $role,
                'subject_scope' => $role === 'functional_skills_tutor' ? 'both' : null,
            ]);
            $this->actingAs($user)->get(route('employer-resources.index'))->assertForbidden();
            $this->getJson(route('employer-resources.state'))->assertForbidden();
            $this->postJson(route('employer-resources.save'), [])->assertForbidden();
        }

        // Unverified or ungranted employer logins cannot enter.
        $ungranted = User::factory()->create(['role' => 'employer']);
        $this->actingAs($ungranted)->get(route('employer-resources.index'))->assertForbidden();

        [, $contact] = $this->grantedEmployer();
        $contact->email_verified_at = null;
        $contact->save();
        $this->actingAs($contact)->get(route('employer-resources.index'))->assertForbidden();
    }

    public function test_revoked_access_inactive_accounts_and_missing_resolver_deny_immediately(): void
    {
        [$admin, $contact, $employer, , $grant] = $this->grantedEmployer();

        $this->actingAs($contact)->get(route('employer-resources.index'))->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Cache-Control', 'no-store, private');

        // Revoke employer grant -> immediate denial.
        EmployerActions::revoke($admin, $admin->auth_version, $grant, $grant->version, 'No longer workplace contact');
        $this->actingAs($contact->fresh())->get(route('employer-resources.index'))->assertForbidden();
        $this->getJson(route('employer-resources.state'))->assertForbidden();

        // Grant again, then deactivate employer organisation -> immediate denial.
        [, $contact2, $employer2] = $this->grantedEmployer();
        $this->actingAs($contact2)->get(route('employer-resources.index'))->assertOk();
        $employer2->active = false;
        $employer2->save();
        $this->get(route('employer-resources.index'))->assertForbidden();

        // Reactivate employer without bumping version back: record_version changed -> still denied.
        $employer2->active = true;
        $employer2->record_version++;
        $employer2->save();
        $this->get(route('employer-resources.index'))->assertForbidden();

        // Mentor role allowed, then duty removed or account blocked -> immediate denial.
        $staff = User::factory()->create(['role' => 'staff']);
        StaffDuty::create(['user_id' => $staff->id, 'mentor' => true, 'history' => []]);
        $this->actingAs($staff)->get(route('employer-resources.index'))->assertOk();
        StaffDuty::where('user_id', $staff->id)->update(['mentor' => false]);
        $this->get(route('employer-resources.index'))->assertForbidden();

        $mentor = User::factory()->create(['role' => 'mentor']);
        $reviewOn = today()->addDays(7)->toDateString();
        AccountLifecycleState::create([
            'user_id' => $mentor->id,
            'version' => 1,
            'status' => 'Restricted',
            'review_on' => $reviewOn,
            'proof' => ['fields' => ['user_id' => $mentor->id, 'version' => 1, 'status' => 'Restricted', 'review_on' => $reviewOn]],
        ]);
        $this->actingAs($mentor)->get(route('employer-resources.index'))->assertForbidden();

        // Missing resolver never silently grants access.
        [, $freshContact] = $this->grantedEmployer();
        config(['employer_resources65.organisation_resolver' => null]);
        $this->actingAs($freshContact)->get(route('employer-resources.index'))->assertForbidden();
        $this->assertFalse(EmployerResourceAccess::allowed($freshContact));
    }

    public function test_user_and_organisation_isolation_and_session_employer_switching(): void
    {
        $content = $this->content();
        $firstPage = $content['courses'][0]['pages'][0];

        [$admin, $contactA, $employer1, $apprentice1] = $this->grantedEmployer();

        // Second named user at the same employer organisation.
        $contactB = User::factory()->create(['role' => 'employer']);
        EmployerActions::grant($admin, $admin->auth_version, $apprentice1, [
            'user_id' => $contactB->id,
            'version' => 0,
            'identity_reference' => 'Second verified contact at employer 1',
        ]);

        // Contact A records topic and attempts to forge user_key/organisation_key in request body.
        $this->actingAs($contactA);
        $this->savePage('employer', $firstPage, $content['version'], [
            'user_key' => (string) $contactB->id,
            'organisation_key' => 'employer:999999',
        ])->assertOk()->assertJsonStructure(['employer:'.$firstPage['id'] => ['at']]);

        $this->assertDatabaseHas('employer_resource_progress65', [
            'user_key' => (string) $contactA->id,
            'organisation_key' => 'employer:'.$employer1->id,
            'course' => 'employer',
            'page' => $firstPage['id'],
        ]);

        // Contact B at same employer sees their own empty state, not Contact A's progress.
        $this->actingAs($contactB)->getJson(route('employer-resources.state'))
            ->assertOk()
            ->assertExactJson([]);

        // Give Contact A a second authorised employer organisation.
        $employer2 = Employer::factory()->create();
        $apprentice2 = Apprentice::factory()->create(['employer_id' => $employer2->id]);
        EmployerActions::grant($admin, $admin->auth_version, $apprentice2, [
            'user_id' => $contactA->id,
            'version' => 0,
            'identity_reference' => 'Authorised at employer 2',
        ]);

        // Switch session context to employer 2 -> separate progress scope.
        $this->actingAs($contactA)
            ->withSession(['employer_resources65.employer_id' => $employer2->id])
            ->getJson(route('employer-resources.state'))
            ->assertOk()
            ->assertExactJson([]);

        $this->actingAs($contactA)
            ->withSession(['employer_resources65.employer_id' => $employer2->id]);
        $this->savePage('employer', $firstPage, $content['version'])->assertOk();
        $this->assertDatabaseHas('employer_resource_progress65', [
            'user_key' => (string) $contactA->id,
            'organisation_key' => 'employer:'.$employer2->id,
            'page' => $firstPage['id'],
        ]);

        // Unauthorised employer ID in session or query string is rejected / ignored.
        $employer3 = Employer::factory()->create();
        $this->actingAs($contactA)
            ->withSession(['employer_resources65.employer_id' => $employer3->id])
            ->get(route('employer-resources.index'))
            ->assertForbidden();

        // Query-string employer_id is ignored when session uses default employer1.
        $this->flushSession();
        $this->actingAs($contactA)
            ->get(route('employer-resources.index', ['employer_id' => $employer3->id]))
            ->assertOk();
        $this->assertSame('employer:'.$employer1->id, EmployerResourceAccess::resolve($contactA));

        // Mentor and admin staff preview scopes resolve distinctly without fabricating employer membership.
        $mentor = User::factory()->create(['role' => 'mentor']);
        Apprentice::factory()->create(['mentor_id' => $mentor->id, 'employer_id' => $employer1->id]);
        $this->assertSame('gta-mentor', EmployerResourceAccess::resolve($mentor));
        $this->actingAs($mentor)->withSession(['employer_resources65.employer_id' => $employer1->id]);
        $this->assertSame('employer:'.$employer1->id, EmployerResourceAccess::resolve($mentor));
        $this->actingAs($mentor)->withSession(['employer_resources65.employer_id' => $employer3->id])
            ->get(route('employer-resources.index'))
            ->assertForbidden();

        $inclusion = User::factory()->create(['role' => 'inclusion_lead']);
        $this->assertSame('gta-staff-preview', EmployerResourceAccess::resolve($inclusion));
        $this->assertSame('gta-staff-preview', EmployerResourceAccess::resolve($admin));
    }

    public function test_validation_rejects_bad_version_unknown_targets_wrong_answers_and_early_commitment(): void
    {
        $content = $this->content();
        $employerCourse = $content['courses'][0];
        $pageWithQuestions = $employerCourse['pages'][1];
        $pageWithoutQuestions = $employerCourse['pages'][0];
        $finalPage = end($employerCourse['pages']);

        [, $contact] = $this->grantedEmployer();
        $this->actingAs($contact);

        // Wrong version -> 409 Conflict.
        $this->savePage('employer', $pageWithoutQuestions, '65.0')->assertStatus(409);

        // Unknown course / unknown page -> 404.
        $this->savePage('unknown', $pageWithoutQuestions)->assertNotFound();
        $this->savePage('employer', ['id' => 'unknown', 'questions' => [], 'final' => false])->assertNotFound();

        // Omitted quick check answer -> 422.
        $this->savePage('employer', $pageWithQuestions, $content['version'], ['answers' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('progress');

        // Wrong quick check answer -> 422.
        $wrong = ($pageWithQuestions['questions'][0]['correct'] + 1) % count($pageWithQuestions['questions'][0]['options']);
        $badAnswers = array_map(fn ($q) => $q['correct'], $pageWithQuestions['questions']);
        $badAnswers[0] = $wrong;
        $this->savePage('employer', $pageWithQuestions, $content['version'], ['answers' => $badAnswers])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('progress');

        // Missing reading confirmation / commitment flag on non-final page -> 422.
        $this->savePage('employer', $pageWithoutQuestions, $content['version'], ['read' => false])
            ->assertUnprocessable();
        $this->savePage('employer', $pageWithoutQuestions, $content['version'], ['commitment' => true])
            ->assertUnprocessable();

        // Commitment before completing prior topics -> 422.
        $this->savePage('employer', $finalPage, $content['version'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('progress');

        // Commitment with false declaration or commitment=false -> 422 even when prior topics done.
        $badDecls = array_fill(0, count($finalPage['declarations']), true);
        $badDecls[0] = false;
        $this->savePage('employer', $finalPage, $content['version'], ['declarations' => $badDecls])
            ->assertUnprocessable();
        $this->savePage('employer', $finalPage, $content['version'], ['commitment' => false])
            ->assertUnprocessable();

        $this->assertDatabaseCount('employer_resource_progress65', 0);
    }

    public function test_complete_both_courses_idempotent_upsert_version_isolation_and_365_day_expiry(): void
    {
        $content = $this->content();
        $mentor = User::factory()->create(['role' => 'mentor']);
        $this->actingAs($mentor);

        $totalTopics = 0;
        $totalQuestions = 0;
        foreach ($content['courses'] as $course) {
            foreach ($course['pages'] as $page) {
                $totalTopics++;
                $totalQuestions += count($page['questions']);
                $response = $this->savePage($course['id'], $page, $content['version']);
                $response->assertOk()->assertJsonStructure([$course['id'].':'.$page['id'] => ['at']]);
            }
        }
        $this->assertSame(23, $totalTopics);
        $this->assertSame(46, $totalQuestions);
        $this->assertDatabaseCount('employer_resource_progress65', 23);

        // Repeat acknowledgement upserts cleanly without duplicating rows.
        $firstPage = $content['courses'][0]['pages'][0];
        $this->savePage('employer', $firstPage, $content['version'])->assertOk();
        $this->assertDatabaseCount('employer_resource_progress65', 23);

        $state = $this->getJson(route('employer-resources.state'))->assertOk()->json();
        $this->assertCount(23, $state);

        // Prior content version rows do not unlock current version commitment.
        [, $contact, $employer] = $this->grantedEmployer();
        $employerCourse = $content['courses'][0];
        $finalPage = end($employerCourse['pages']);
        foreach ($employerCourse['pages'] as $page) {
            if ($page['final']) {
                continue;
            }
            DB::table('employer_resource_progress65')->insert([
                'user_key' => (string) $contact->id,
                'organisation_key' => 'employer:'.$employer->id,
                'content_version' => '65.0',
                'course' => 'employer',
                'page' => $page['id'],
                'score' => count($page['questions']),
                'commitment' => false,
                'completed_at' => now()->utc(),
            ]);
        }
        $this->actingAs($contact);
        $this->savePage('employer', $finalPage, $content['version'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('progress');

        // 365-day expiry: complete all non-final topics, travel 366 days forward -> blocked until renewed.
        foreach ($employerCourse['pages'] as $page) {
            if (! $page['final']) {
                $this->savePage('employer', $page, $content['version'])->assertOk();
            }
        }
        $this->travel(366)->days();
        $this->savePage('employer', $finalPage, $content['version'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('progress');

        foreach ($employerCourse['pages'] as $page) {
            if (! $page['final']) {
                $this->savePage('employer', $page, $content['version'])->assertOk();
            }
        }
        $this->savePage('employer', $finalPage, $content['version'])->assertOk();
    }

    public function test_dashboard_links_navigation_help_pdfs_and_referral_configuration(): void
    {
        [, $contact] = $this->grantedEmployer();
        $this->actingAs($contact)->get(route('employer-actions.index'))
            ->assertOk()
            ->assertSee('Employer & Mentor Resources')
            ->assertSee(route('employer-resources.index'), false)
            ->assertSee('Reviews waiting for my sign-off')
            ->assertSee('Workplace support summaries shared with me');

        // Ungranted employer does not see the dashboard link or sidebar link.
        $ungranted = User::factory()->create(['role' => 'employer']);
        $this->actingAs($ungranted)->get(route('employer-actions.index'))
            ->assertOk()
            ->assertDontSee(route('employer-resources.index'), false);

        // Mentor sees the dashboard panel on their landing page (/reviews) and sidebar link.
        $mentor = User::factory()->create(['role' => 'mentor']);
        $this->actingAs($mentor)->get(route('reviews.index'))
            ->assertOk()
            ->assertSee('Employer & Mentor Resources')
            ->assertSee(route('employer-resources.index'), false);

        // Contextual help and referral URL propagation vs fallback.
        $this->actingAs($contact)->get(route('employer-resources.index'))
            ->assertOk()
            ->assertSee('Help with this page: Use employer and mentor resources')
            ->assertSee('"safeguardingUrl":null', false)
            ->assertSee('"supportUrl":null', false);

        config([
            'employer_resources65.safeguarding_url' => '/fictional-safeguarding-create',
            'employer_resources65.support_url' => '/fictional-support-create',
        ]);
        $this->actingAs($contact)->get(route('employer-resources.index'))
            ->assertOk()
            ->assertSee('\/fictional-safeguarding-create', false)
            ->assertSee('\/fictional-support-create', false);

        // Host theme alignment assets and downloadable PDF guides exist.
        $this->assertStringContainsString('html[data-theme=dark] .gta65:not([data-theme=light])', file_get_contents(public_path('assets/resources65.css')));
        $this->assertStringContainsString("getElementById('theme-toggle')", file_get_contents(public_path('assets/resources65.js')));
        $this->assertFileExists(public_path('resources65/Doncaster_GTA_Safe_Apprenticeships_Portal_Content_Pack.pdf'));
        $this->assertFileExists(public_path('resources65/Doncaster_GTA_Mentoring_Apprentices_Portal_Content_Pack.pdf'));
    }

    public function test_migration_up_and_down_preserves_unrelated_tables(): void
    {
        [, $contact, , $apprentice] = $this->grantedEmployer();

        $migration = require database_path('migrations/2026_11_17_000065_create_employer_resource_progress_table.php');
        $this->assertTrue(Schema::hasTable('employer_resource_progress65'));

        $migration->down();
        $this->assertFalse(Schema::hasTable('employer_resource_progress65'));
        $this->assertNotNull($contact->fresh());
        $this->assertNotNull($apprentice->fresh());

        $migration->up();
        $this->assertTrue(Schema::hasTable('employer_resource_progress65'));
        $this->assertTrue(Schema::hasColumns('employer_resource_progress65', [
            'id', 'user_key', 'organisation_key', 'content_version', 'course', 'page', 'score', 'commitment', 'completed_at',
        ]));
    }
}
