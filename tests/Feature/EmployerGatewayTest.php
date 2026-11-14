<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\Employer;
use App\Models\EmployerApprenticeGrant;
use App\Models\EmployerPortalAction;
use App\Models\ExtraTraining;
use App\Models\GatewayReadinessPlan;
use App\Models\LearningTask;
use App\Models\Programme;
use App\Models\ProgrammeOutcome;
use App\Models\ProgressReviewDraft;
use App\Models\Qualification;
use App\Models\SmartAction;
use App\Models\User;
use App\Notifications\AccountOnboardingLink;
use App\Support\AccountOnboarding;
use App\Support\EmployerActions;
use App\Support\GatewayReadiness;
use App\Support\ReviewedImports;
use App\Support\TaskCatalogue;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\ConfiguresStaging;
use Tests\Support\AuditFault;
use Tests\TestCase;

class EmployerGatewayTest extends TestCase
{
    use ConfiguresStaging,LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-14 12:00:00'));
    }

    private function fixture(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tutor = User::factory()->create(['role' => 'tutor']);
        $contact = User::factory()->create(['role' => 'employer']);
        $e = Employer::factory()->create();
        $p = Programme::where('standard_code', 'ST0499')->where('version', '1.3')->first() ?? Programme::factory()->create(['standard_code' => 'ST0499', 'version' => '1.3']);
        $a = Apprentice::factory()->create(['tutor_id' => $tutor->id, 'employer_id' => $e->id, 'programme_id' => $p->id, 'start_date' => today()->subDays(100)]);
        $g = EmployerActions::grant($admin, $admin->auth_version, $a, ['user_id' => $contact->id, 'version' => 0, 'identity_reference' => 'Fictional independent workplace authority check']);

        ProgrammeOutcome::factory()->create(['apprentice_id' => $a->id, 'context' => ['status' => 'Active', 'sourceRef' => 'Fictional recorded active status']]);

        return [$admin, $tutor, $a, $contact, $g];
    }

    private function publish(User $u, Apprentice $a, EmployerApprenticeGrant $g, string $kind = 'target'): EmployerPortalAction
    {
        $source = match ($kind) {
            'review' => ProgressReviewDraft::factory()->create(['apprentice_id' => $a->id, 'state' => 'Ready for signatures', 'fields' => ['summary' => 'PRIVATE-DRAFT-TEXT']]),'workplace' => ExtraTraining::factory()->create(['apprentice_id' => $a->id]),default => SmartAction::factory()->create(['apprentice_id' => $a->id, 'owner' => 'Employer / workplace mentor', 'support' => 'PRIVATE-TARGET-SUPPORT'])
        };

        return EmployerActions::publish($u, $u->auth_version, $a, ['request_token' => (string) Str::uuid(), 'grant_id' => $g->id, 'kind' => $kind, 'source_id' => $source->id, 'title' => 'SHARED REQUEST TITLE', 'detail' => 'SHARED CHECKED SUMMARY', 'due_date' => null]);
    }

    private function reply(EmployerPortalAction $x): array
    {
        return ['version' => $x->version, 'response' => 'Confirmed', 'note' => 'Fictional checked employer response', 'confirmed' => 1];
    }

    private function criteria(): array
    {
        $rows = [];
        foreach (array_keys(GatewayReadiness::CATEGORIES) as $c) {
            $rows[] = ['category' => $c, 'kind' => 'manual', 'label' => 'Fictional sourced '.$c.' criterion'];
        }

        return $rows;
    }

    private function plan(User $u, Apprentice $a): GatewayReadinessPlan
    {
        return GatewayReadiness::prepare($u, $u->auth_version, $a, ['cohort' => 'Fictional2026 cohort', 'source_reference' => 'FICTIONAL TEST CRITERIA ONLY — not actual standard requirements', 'criteria' => $this->criteria()]);
    }

    private function approve(User $admin, GatewayReadinessPlan $p): void
    {
        GatewayReadiness::change($admin, $admin->auth_version, $p, ['version' => $p->version, 'state' => 'Approved', 'note' => 'Fictional independent source/applicability check']);
    }

    private function checkData(GatewayReadinessPlan $p, string $key = 'c1', int $version = 0): array
    {
        return ['plan_version' => $p->fresh()->version, 'version' => $version, 'criterion_key' => $key, 'state' => 'Met', 'evidence_reference' => 'Fictional shareable evidence reference', 'note' => 'Fictional provider check; not a final Gateway decision', 'checked_on' => today()->toDateString(), 'confirmed' => 1];
    }

    public function test_no_company_name_domain_or_unverified_account_auto_grants_access(): void
    {
        [$admin,$t,$a,$contact,$g] = $this->fixture();
        $x = $this->publish($t, $a, $g);
        $other = User::factory()->create(['role' => 'employer']);
        $this->actingAs($other)->get(route('employer-actions.index'))->assertOk()->assertDontSee('SHARED REQUEST TITLE');
        $this->get(route('employer-actions.show', $x))->assertForbidden();
        $contact->email_verified_at = null;
        $contact->save();
        $this->actingAs($contact)->get(route('employer-actions.index'))->assertForbidden();
    }

    public function test_named_grant_is_profile_specific_and_management_only(): void
    {
        [$admin,$t,$a,$contact,$g] = $this->fixture();
        $this->assertTrue(EmployerActions::valid($g, $a));
        $other = Apprentice::factory()->create(['employer_id' => $a->employer_id]);
        $this->assertFalse(EmployerActions::valid($g, $other));
        $this->actingAs($t)->get(route('employer-grants.index', $a))->assertForbidden();
        $this->post(route('employer-grants.save', $a), ['user_id' => $contact->id, 'version' => 1, 'identity_reference' => 'Fake', 'confirmed' => 1])->assertForbidden();
    }

    public function test_admin_can_create_unverified_login_without_password_or_grant(): void
    {
        [$admin] = $this->fixture();
        $this->actingAs($admin)->post('/employer-accounts', ['name' => 'Fictional New Contact', 'email' => 'new-contact@example.test'])->assertRedirect()->assertSessionHasNoErrors();
        $u = User::where('email', 'new-contact@example.test')->firstOrFail();
        $this->assertSame('employer', $u->role);
        $this->assertNull($u->email_verified_at);
        $this->assertSame(60, strlen($u->password));
        $this->assertSame(0, EmployerApprenticeGrant::where('user_id', $u->id)->count());
        $this->get('/employer-accounts')->assertOk()->assertDontSee($u->password);
    }

    public function test_existing_case_insensitive_email_cannot_be_repurposed(): void
    {
        [$admin,$t] = $this->fixture();
        $before = $t->fresh()->getAttributes();
        $this->actingAs($admin)->post('/employer-accounts', ['name' => 'Wrong contact', 'email' => strtoupper($t->email)])->assertSessionHasErrors('email');
        $this->assertSame($before, $t->fresh()->getAttributes());
    }

    public function test_employer_account_and_grant_pages_render_with_current_contacts(): void
    {
        [$admin,$t,$a,$contact] = $this->fixture();
        $this->actingAs($admin)->get('/employer-accounts')->assertOk()->assertSee($contact->email);
        $this->get(route('employer-grants.index', $a))->assertOk()->assertSee('Verify employer access');
        $this->actingAs($t)->get(route('employer-actions.staff', $a))->assertOk()->assertSee('Publish a checked employer request');
    }

    public function test_employer_gets_only_shared_snapshot_not_private_source_or_other_routes(): void
    {
        [$admin,$t,$a,$contact,$g] = $this->fixture();
        $x = $this->publish($t, $a, $g, 'review');
        $this->actingAs($contact)->get(route('employer-actions.show', $x))->assertOk()->assertSee('SHARED CHECKED SUMMARY')->assertDontSee('PRIVATE-DRAFT-TEXT')->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('apprentices.show', $a))->assertForbidden();
        $this->get(route('gateway.show', $a))->assertForbidden();
        $this->get(route('employer-actions.staff', $a))->assertForbidden();
    }

    public function test_review_response_is_recorded_without_signature_or_review_completion(): void
    {
        [$admin,$t,$a,$contact,$g] = $this->fixture();
        $x = $this->publish($t, $a, $g, 'review');
        $r = ProgressReviewDraft::find($x->source_id);
        $before = $r->fresh()->getAttributes();
        $this->actingAs($contact)->post(route('employer-actions.respond', $x), $this->reply($x))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Responded', $x->fresh()->state);
        $this->assertSame($contact->id, $x->fresh()->responded_by);
        $this->assertSame($before, $r->fresh()->getAttributes());
        $this->assertDatabaseCount('signed_review_records', 0);
    }

    public function test_workplace_confirmation_does_not_award_otj_or_compensation(): void
    {
        [$admin,$t,$a,$contact,$g] = $this->fixture();
        $x = $this->publish($t, $a, $g, 'workplace');
        $s = ExtraTraining::find($x->source_id);
        $before = $s->fresh()->getAttributes();
        $this->actingAs($contact)->post(route('employer-actions.respond', $x), $this->reply($x))->assertRedirect();
        $this->assertSame($before, $s->fresh()->getAttributes());
        $this->assertNull($s->fresh()->approval);
        $this->assertNull($s->fresh()->compensation);
    }

    public function test_target_response_does_not_complete_original_target(): void
    {
        [$admin,$t,$a,$contact,$g] = $this->fixture();
        $x = $this->publish($t, $a, $g);
        $this->actingAs($contact)->post(route('employer-actions.respond', $x), $this->reply($x))->assertRedirect();
        $this->assertSame('To do', SmartAction::find($x->source_id)->status);
        $this->actingAs($t)->post(route('employer-actions.check', $x), ['version' => 2, 'state' => 'Checked', 'note' => 'Fictional staff source check'])->assertRedirect();
        $this->assertSame('Checked', $x->fresh()->state);
        $this->assertSame('To do', SmartAction::find($x->source_id)->status);
    }

    public function test_changed_source_or_replayed_response_cannot_be_accepted(): void
    {
        [$admin,$t,$a,$contact,$g] = $this->fixture();
        $x = $this->publish($t, $a, $g);
        SmartAction::whereKey($x->source_id)->update(['title' => 'Changed source']);
        $this->actingAs($contact)->post(route('employer-actions.respond', $x), $this->reply($x))->assertSessionHasErrors('actions');
        $this->assertSame('Pending', $x->fresh()->state);
        $y = $this->publish($t, $a, $g);
        $this->post(route('employer-actions.respond', $y), $this->reply($y))->assertRedirect();
        $this->post(route('employer-actions.respond', $y), $this->reply($y))->assertSessionHasErrors('actions');
        $this->assertSame(2, $y->fresh()->version);
    }

    public function test_revocation_and_reverification_hide_old_requests_even_on_direct_link(): void
    {
        [$admin,$t,$a,$contact,$g] = $this->fixture();
        $x = $this->publish($t, $a, $g);
        EmployerActions::revoke($admin, $admin->auth_version, $g, 1, 'Fictional revocation');
        $this->actingAs($contact)->get(route('employer-actions.show', $x))->assertForbidden();
        $this->get('/employer-actions')->assertOk()->assertDontSee('SHARED REQUEST TITLE');
        EmployerActions::grant($admin, $admin->auth_version, $a, ['user_id' => $contact->id, 'version' => 2, 'identity_reference' => 'New identity check']);
        $this->get('/employer-actions')->assertDontSee('SHARED REQUEST TITLE');
        $this->get(route('employer-actions.show', $x))->assertForbidden();
    }

    public function test_employer_profile_directory_or_login_identity_changes_invalidate_grant(): void
    {
        foreach (['profile', 'employer', 'name', 'role', 'active'] as $change) {
            [$admin,$t,$a,$contact,$g] = $this->fixture();
            if ($change === 'profile') {
                $a->employer_id = Employer::factory()->create()->id;
                $a->save();
            } elseif ($change === 'employer') {
                $e = Employer::find($a->employer_id);
                $e->record_version++;
                $e->save();
            } else {
                $contact->{$change} = match ($change) {
                    'name' => 'Changed contact','role' => 'staff',default => false
                };
                $contact->save();
            }$this->assertFalse(EmployerActions::valid($g, $a->fresh()));
        }
    }

    public function test_cross_profile_source_and_wrong_target_owner_are_refused(): void
    {
        [$admin,$t,$a,$contact,$g] = $this->fixture();
        $foreign = SmartAction::factory()->create(['owner' => 'Employer / workplace mentor']);
        $data = ['request_token' => (string) Str::uuid(), 'grant_id' => $g->id, 'source_choice' => 'target:'.$foreign->id, 'title' => 'Fictional request', 'detail' => 'Fictional detail', 'confirmed' => 1];
        $this->actingAs($t)->post(route('employer-actions.publish', $a), $data)->assertSessionHasErrors('actions');
        $wrong = SmartAction::factory()->create(['apprentice_id' => $a->id, 'owner' => 'Apprentice']);
        $data['source_choice'] = 'target:'.$wrong->id;
        $this->post(route('employer-actions.publish', $a), $data)->assertSessionHasErrors('actions');
        $this->assertDatabaseCount('employer_portal_actions', 0);
    }

    public function test_publishing_requires_share_check_and_idempotency_uses_one_request(): void
    {
        [$admin,$t,$a,$contact,$g] = $this->fixture();
        $source = SmartAction::factory()->create(['apprentice_id' => $a->id, 'owner' => 'Employer / workplace mentor']);
        $data = ['request_token' => (string) Str::uuid(), 'grant_id' => $g->id, 'source_choice' => 'target:'.$source->id, 'title' => 'Shared', 'detail' => 'Shared detail'];
        $this->actingAs($t)->post(route('employer-actions.publish', $a), $data)->assertSessionHasErrors('confirmed');
        $data['confirmed'] = 1;
        $this->post(route('employer-actions.publish', $a), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('employer-actions.publish', $a), $data)->assertRedirect();
        $this->assertDatabaseCount('employer_portal_actions', 1);
    }

    public function test_wrong_account_response_and_missing_confirmation_fail(): void
    {
        [$admin,$t,$a,$contact,$g] = $this->fixture();
        $x = $this->publish($t, $a, $g);
        $other = User::factory()->create(['role' => 'employer']);
        $this->actingAs($other)->post(route('employer-actions.respond', $x), $this->reply($x))->assertForbidden();
        $d = $this->reply($x);
        unset($d['confirmed']);
        $this->actingAs($contact)->post(route('employer-actions.respond', $x), $d)->assertSessionHasErrors('confirmed');
        $this->assertSame('Pending', $x->fresh()->state);
    }

    public function test_audit_failure_rolls_back_employer_reply_and_history(): void
    {
        [$admin,$t,$a,$contact,$g] = $this->fixture();
        $x = $this->publish($t, $a, $g);
        AuditFault::install('employer_reply_audit_failure', 'audit_events', 'Fictional failure');
        $this->actingAs($contact)->post(route('employer-actions.respond', $x), $this->reply($x))->assertStatus(500);
        $this->assertSame('Pending', $x->fresh()->state);
        $this->assertNull($x->fresh()->responded_at);
        $this->assertCount(1, $x->fresh()->history);
    }

    public function test_response_and_publication_html_are_escaped(): void
    {
        [$admin,$t,$a,$contact,$g] = $this->fixture();
        $x = $this->publish($t, $a, $g);
        $x->detail = '<script>UNTRUSTED-SNAPSHOT</script>';
        $source = $x->source;
        $source['publication']['detail'] = $x->detail;
        $x->source = $source;
        $x->source_hash = ReviewedImports::hash($source);
        $x->save();
        $this->actingAs($contact)->get(route('employer-actions.show', $x))->assertOk()->assertSee('&lt;script&gt;UNTRUSTED-SNAPSHOT&lt;/script&gt;', false)->assertDontSee('<script>UNTRUSTED-SNAPSHOT</script>', false);
    }

    public function test_unconfigured_gateway_has_no_invented_requirements_or_ready_status(): void
    {
        [$admin,$t,$a] = $this->fixture();
        $p = GatewayReadiness::position($a);
        $this->assertFalse($p['current']);
        $this->assertFalse($p['all_met']);
        $this->assertSame(0, $p['total']);
        $this->actingAs($t)->get(route('gateway.show', $a))->assertOk()->assertSee('readiness not assessed');
    }

    public function test_criteria_cover_all_categories_and_need_exact_programme_context(): void
    {
        [$admin,$t,$a] = $this->fixture();
        $data = ['cohort' => 'Fictional', 'source_reference' => 'Fictional source', 'criteria' => array_slice($this->criteria(), 0, 5), 'confirmed' => 1];
        $this->actingAs($t)->post(route('gateway.prepare', $a), $data)->assertSessionHasErrors('criteria');
        $a->programme_id = null;
        $a->save();
        $data['criteria'] = $this->criteria();
        $this->post(route('gateway.prepare', $a), $data)->assertSessionHasErrors('gateway');
        $this->assertDatabaseCount('gateway_readiness_plans', 0);
    }

    public function test_criteria_require_different_management_approver_and_remain_unknown_before_approval(): void
    {
        [$admin,$t,$a] = $this->fixture();
        $p = $this->plan($t, $a);
        $this->assertFalse(GatewayReadiness::position($a)['current']);
        $this->actingAs($t)->post(route('gateway.change', $p), ['version' => 1, 'state' => 'Approved', 'note' => 'Cannot self approve', 'confirmed' => 1])->assertForbidden();
        $own = $this->plan($admin, $a);
        $this->actingAs($admin)->post(route('gateway.change', $own), ['version' => 1, 'state' => 'Approved', 'note' => 'Same author', 'confirmed' => 1])->assertSessionHasErrors('gateway');
        $this->approve($admin, $p);
        $p->refresh();
        $this->assertSame(2, $p->version);
        $position = GatewayReadiness::position($a);
        $this->assertTrue($position['current']);
        $this->assertSame(0, $position['met']);
        $this->assertSame(6, $position['total']);
    }

    public function test_learner_can_read_current_checks_but_cannot_manage_criteria_or_checks(): void
    {
        [$admin,$t,$a] = $this->fixture();
        $u = User::factory()->create(['role' => 'apprentice']);
        $a->user_id = $u->id;
        $a->save();
        $p = $this->plan($t, $a);
        $this->approve($admin, $p);
        $this->actingAs($u)->get(route('gateway.show', $a))->assertOk()->assertSee('Fictional sourced')->assertDontSee('Save criteria for independent approval');
        $this->post(route('gateway.check', $p), $this->checkData($p))->assertForbidden();
        $this->post(route('gateway.prepare', $a), ['cohort' => 'x', 'source_reference' => 'x', 'criteria' => $this->criteria(), 'confirmed' => 1])->assertForbidden();
    }

    public function test_manual_checks_are_shareable_sourced_stale_safe_and_not_formal_gateway(): void
    {
        [$admin,$t,$a] = $this->fixture();
        $p = $this->plan($t, $a);
        $this->approve($admin, $p);
        foreach (range(1, 6) as $i) {
            GatewayReadiness::check($t, $t->auth_version, $p, $this->checkData($p, 'c'.$i));
        }$position = GatewayReadiness::position($a);
        $this->assertSame(6, $position['met']);
        $this->assertTrue($position['all_met']);
        $this->assertStringContainsString('provider Gateway review still required', $position['status']);
        $this->assertSame('Active', ProgrammeOutcome::where('apprentice_id', $a->id)->first()->context['status']);
        $this->actingAs($t)->post(route('gateway.check', $p), $this->checkData($p))->assertSessionHasErrors('gateway');
    }

    public function test_taskset_needs_exact_fifty_source_tasks_and_still_separate_manual_requirements(): void
    {
        [$admin,$t,$a] = $this->fixture();
        $rows = $this->criteria();
        $rows[0]['kind'] = 'taskset';
        $p = GatewayReadiness::prepare($t, $t->auth_version, $a, ['cohort' => 'Fictional', 'source_reference' => 'Fictional source', 'criteria' => $rows]);
        $this->approve($admin, $p);
        $this->assertSame('Unknown', GatewayReadiness::position($a)['rows'][0]['state']);
        foreach (TaskCatalogue::tasks('autocare') as $task) {
            LearningTask::factory()->create(['apprentice_id' => $a->id, 'reference' => $task['id'], 'block' => $task['block'], 'template' => TaskCatalogue::template($task['id']), 'status' => 'Approved']);
        }$position = GatewayReadiness::position($a);
        $this->assertSame('Met', $position['rows'][0]['state']);
        $this->assertFalse($position['all_met']);
        $this->assertSame(1, $position['met']);
    }

    public function test_qualification_result_needs_real_achieved_date_and_exemption_is_not_assumed(): void
    {
        [$admin,$t,$a] = $this->fixture();
        $q = Qualification::factory()->create(['apprentice_id' => $a->id]);
        $rows = $this->criteria();
        $rows[1]['kind'] = 'qualification';
        $rows[1]['qualification_id'] = $q->id;
        $p = GatewayReadiness::prepare($t, $t->auth_version, $a, ['cohort' => 'Fictional', 'source_reference' => 'Fictional source', 'criteria' => $rows]);
        $this->approve($admin, $p);
        $this->assertSame('Missing', GatewayReadiness::position($a)['rows'][1]['state']);
        $q->status = 'Exemption confirmed';
        $q->save();
        $this->assertSame('Missing', GatewayReadiness::position($a)['rows'][1]['state']);
        $q->status = 'Achieved';
        $q->achievement_date = today()->subDay();
        $q->save();
        $this->assertSame('Met', GatewayReadiness::position($a)['rows'][1]['state']);
    }

    public function test_record_change_invalidates_manual_checks_and_context_change_invalidates_plan(): void
    {
        [$admin,$t,$a] = $this->fixture();
        $p = $this->plan($t, $a);
        $this->approve($admin, $p);
        GatewayReadiness::check($t, $t->auth_version, $p, $this->checkData($p));
        $this->assertSame('Met', GatewayReadiness::position($a)['rows'][0]['state']);
        LearningTask::factory()->create(['apprentice_id' => $a->id]);
        $this->assertSame('Unknown', GatewayReadiness::position($a)['rows'][0]['state']);
        $a->start_date = today()->subDays(90);
        $a->save();
        $this->assertFalse(GatewayReadiness::position($a)['current']);
    }

    public function test_criteria_supersession_and_withdrawal_do_not_reuse_old_checks(): void
    {
        [$admin,$t,$a] = $this->fixture();
        $p = $this->plan($t, $a);
        $this->approve($admin, $p);
        GatewayReadiness::check($t, $t->auth_version, $p, $this->checkData($p));
        $new = $this->plan($t, $a);
        $this->approve($admin, $new);
        $this->assertSame('Withdrawn', $p->fresh()->state);
        $this->assertSame(0, GatewayReadiness::position($a)['met']);
        GatewayReadiness::change($t, $t->auth_version, $new->fresh(), ['version' => 2, 'state' => 'Withdrawn', 'note' => 'Fictional source correction']);
        $this->assertFalse(GatewayReadiness::position($a)['current']);
    }

    public function test_foreign_qualification_and_automatic_manual_check_forgery_fail(): void
    {
        [$admin,$t,$a] = $this->fixture();
        $q = Qualification::factory()->create();
        $rows = $this->criteria();
        $rows[1]['kind'] = 'qualification';
        $rows[1]['qualification_id'] = $q->id;
        $this->actingAs($t)->post(route('gateway.prepare', $a), ['cohort' => 'Fictional', 'source_reference' => 'Fictional', 'criteria' => $rows, 'confirmed' => 1])->assertSessionHasErrors('gateway');
        $rows[1]['kind'] = 'manual';
        $rows[0]['kind'] = 'taskset';
        $p = GatewayReadiness::prepare($t, $t->auth_version, $a, ['cohort' => 'Fictional', 'source_reference' => 'Fictional', 'criteria' => $rows]);
        $this->approve($admin, $p);
        $this->post(route('gateway.check', $p), $this->checkData($p))->assertSessionHasErrors('gateway');
    }

    public function test_check_requires_evidence_note_date_and_share_confirmation(): void
    {
        [$admin,$t,$a] = $this->fixture();
        $p = $this->plan($t, $a);
        $this->approve($admin, $p);
        $this->actingAs($t);
        foreach ([['note' => ''], ['evidence_reference' => ''], ['checked_on' => today()->addDay()->toDateString()], ['confirmed' => 0]] as $change) {
            $this->post(route('gateway.check', $p), array_replace($this->checkData($p), $change))->assertSessionHasErrors();
        }$this->assertDatabaseCount('gateway_readiness_checks', 0);
    }

    public function test_unrelated_and_employer_accounts_cannot_read_gateway(): void
    {
        [$admin,$t,$a,$contact] = $this->fixture();
        foreach ([$contact, User::factory()->create(['role' => 'tutor']), User::factory()->create(['role' => 'mentor']), User::factory()->create(['role' => 'functional_skills_tutor'])] as $u) {
            $this->actingAs($u)->get(route('gateway.show', $a))->assertForbidden();
        }
    }

    public function test_gateway_audit_failure_rolls_back_check_and_criteria(): void
    {
        [$admin,$t,$a] = $this->fixture();
        $p = $this->plan($t, $a);
        $this->approve($admin, $p);
        AuditFault::install('gateway_audit_failure', 'audit_events', 'Fictional failure');
        $this->actingAs($t)->post(route('gateway.check', $p), $this->checkData($p))->assertStatus(500);
        $this->assertDatabaseCount('gateway_readiness_checks', 0);
        $this->post(route('gateway.prepare', $a), ['cohort' => 'Fictional', 'source_reference' => 'Fictional', 'criteria' => $this->criteria(), 'confirmed' => 1])->assertStatus(500);
        $this->assertDatabaseCount('gateway_readiness_plans', 1);
    }

    public function test_invitation_purpose_is_separate_default_off_and_staff_invite_stays_denied(): void
    {
        [$admin,$t,$a,$contact] = $this->fixture();
        $contact->email_verified_at = null;
        $contact->save();
        $this->assertFalse(AccountOnboarding::eligible($contact, 'employer_invite'));
        $this->configureStaging();
        config(['onboarding.enabled' => true, 'onboarding.recipients' => [$contact->email], 'mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'smtp', 'scheme' => 'smtps', 'host' => 'smtp.example.test', 'port' => 465, 'timeout' => 10], 'mail.from.address' => 'portal@example.test']);
        $this->assertTrue(AccountOnboarding::eligible($contact, 'employer_invite'));
        $this->assertFalse(AccountOnboarding::eligible($contact, 'invite'));
        Notification::fake();
        DB::transaction(fn () => AccountOnboarding::issue($contact, 'employer_invite', $admin));
        $link = Notification::sent($contact, AccountOnboardingLink::class)->last()->link;
        $this->assertStringContainsString('/employer-invitation/', $link);
        $this->assertTrue(AccountOnboarding::valid($contact, 'employer_invite', basename($link)));
        $this->assertFalse(AccountOnboarding::valid($contact, 'invite', basename($link)));
        $admin->active = false;
        $admin->save();
        $this->assertFalse(AccountOnboarding::valid($contact, 'employer_invite', basename($link)));
    }

    public function test_schema_round_trip_preserves_previous_reviews_and_evidence(): void
    {
        [$admin,$t,$a] = $this->fixture();
        $m = require database_path('migrations/2026_11_14_230000_add_employer_actions_and_gateway_checks.php');
        $m->down();
        $this->assertFalse(Schema::hasTable('employer_portal_actions'));
        $this->assertFalse(Schema::hasTable('gateway_readiness_plans'));
        $this->assertTrue(Schema::hasTable('review_preparation_briefs'));
        $this->assertTrue(Schema::hasColumn('task_evidence', 'description'));
        $m->up();
        $this->assertTrue(Schema::hasTable('gateway_readiness_checks'));
        $this->assertNotNull($a->fresh());
    }

    public function test_gateway_worklist_scope_filters_and_no_formal_ready_label(): void
    {
        [$admin,$t,$a] = $this->fixture();
        $other = Apprentice::factory()->create(['first_name' => 'UNRELATED-GATEWAY-SECRET']);
        $this->actingAs($t)->get('/gateway-preparation')->assertOk()->assertSee($a->reference)->assertDontSee('UNRELATED-GATEWAY-SECRET')->assertSee('not Gateway approvals');
        $this->get('/gateway-preparation?view=not-assessed')->assertOk()->assertSee($a->reference);
        $this->get('/gateway-preparation?q=NO-SUCH')->assertOk()->assertSee('0 apprentices match');
        $this->get('/gateway-preparation?view=bad')->assertSessionHasErrors('view');
        $this->actingAs(User::factory()->create(['role' => 'employer']))->get('/gateway-preparation')->assertForbidden();
    }

    public function test_gateway_pdf_is_private_readable_and_excludes_private_history(): void
    {
        [$admin,$t,$a] = $this->fixture();
        $p = $this->plan($t, $a);
        $this->approve($admin, $p);
        GatewayReadiness::check($t, $t->auth_version, $p, $this->checkData($p));
        $this->actingAs($t);
        $r = $this->get(route('gateway.pdf', $a))->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringStartsWith('%PDF-', $r->getContent());
        $html = view('pdf.gateway', ['logo' => '', 'apprentice' => $a, 'position' => GatewayReadiness::position($a)])->render();
        $this->assertStringContainsString('not a Gateway approval', $html);
        $this->assertStringContainsString('Fictional shareable evidence reference', $html);
        $this->assertStringNotContainsString('Fictional independent workplace authority check', $html);
        $this->actingAs(User::factory()->create(['role' => 'employer']))->get(route('gateway.pdf', $a))->assertForbidden();
    }

    public function test_new_employer_reply_appears_in_main_tutor_attention_until_checked(): void
    {
        [$admin,$t,$a,$contact,$g] = $this->fixture();
        $x = $this->publish($t, $a, $g);
        EmployerActions::respond($contact, $contact->auth_version, $x, $this->reply($x));
        $this->actingAs($t)->get('/needs-attention?reason=employer')->assertOk()->assertSee('Employer reply awaiting staff check')->assertSee('#employer-action-'.$x->id, false);
        EmployerActions::staffChange($t, $t->auth_version, $x->fresh(), ['version' => 2, 'state' => 'Checked', 'note' => 'Fictional check']);
        $this->get('/needs-attention?reason=employer')->assertDontSee('Employer reply awaiting staff check');
    }

    public function test_employer_grant_revocation_invalidates_recorded_manual_readiness_checks(): void
    {
        [$admin,$t,$a,$contact,$g] = $this->fixture();
        $p = $this->plan($t, $a);
        $this->approve($admin, $p);
        GatewayReadiness::check($t, $t->auth_version, $p, $this->checkData($p));
        $this->assertSame('Met', GatewayReadiness::position($a)['rows'][0]['state']);
        EmployerActions::revoke($admin, $admin->auth_version, $g, 1, 'Fictional authority correction');
        $this->assertSame('Unknown', GatewayReadiness::position($a)['rows'][0]['state']);
    }

    public function test_programme_status_change_invalidates_published_readiness_basis(): void
    {
        [$admin,$t,$a] = $this->fixture();
        $p = $this->plan($t, $a);
        $this->approve($admin, $p);
        $outcome = ProgrammeOutcome::where('apprentice_id', $a->id)->first();
        $outcome->context = ['status' => 'Withdrawn', 'sourceRef' => 'Fictional checked withdrawal'];
        $outcome->save();
        $this->assertFalse(GatewayReadiness::position($a)['current']);
        $new = $this->plan($t, $a);
        $this->actingAs($admin)->post(route('gateway.change', $new), ['version' => 1, 'state' => 'Approved', 'note' => 'Must not approve inactive cohort', 'confirmed' => 1])->assertSessionHasErrors('gateway');
    }

    public function test_linked_target_source_changes_are_bound_and_completed_task_cannot_request_confirmation(): void
    {
        [$admin,$t,$a,$contact,$g] = $this->fixture();
        $task = LearningTask::factory()->create(['apprentice_id' => $a->id]);
        $target = SmartAction::factory()->create(['apprentice_id' => $a->id, 'learning_task_id' => $task->id, 'owner' => 'Employer / workplace mentor']);
        $data = ['request_token' => (string) Str::uuid(), 'grant_id' => $g->id, 'kind' => 'target', 'source_id' => $target->id, 'title' => 'Fictional linked target', 'detail' => 'Fictional shareable detail', 'due_date' => null];
        $x = EmployerActions::publish($t, $t->auth_version, $a, $data);
        $task->status = 'Approved';
        $task->save();
        $this->assertFalse(EmployerActions::current($x, $a, $g));
        $this->actingAs($contact)->post(route('employer-actions.respond', $x), $this->reply($x))->assertSessionHasErrors('actions');
    }

    public function test_guest_employer_invitation_accepts_once_without_granting_apprentice_access(): void
    {
        [$admin,$t,$a,$contact,$g] = $this->fixture();
        EmployerActions::revoke($admin,$admin->auth_version,$g,1,'Fictional prior grant revoked');
        $contact->email_verified_at = null;
        $contact->save();
        $this->configureStaging();
        config(['onboarding.enabled' => true, 'onboarding.recipients' => [$contact->email], 'mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'smtp', 'scheme' => 'smtps', 'host' => 'smtp.example.test', 'port' => 465, 'timeout' => 10], 'mail.from.address' => 'portal@example.test']);
        Notification::fake();
        DB::transaction(fn () => AccountOnboarding::issue($contact, 'employer_invite', $admin));
        $path = parse_url(Notification::sent($contact, AccountOnboardingLink::class)->last()->link, PHP_URL_PATH);
        $this->get('https://staging.example.test'.$path)->assertOk()->assertSee('Set your employer password');
        $password = 'Fictional-employer-password-27!';
        $this->withSession(['_token' => 'fictional-csrf'])->post('https://staging.example.test'.$path, ['_token' => 'fictional-csrf', 'password' => $password, 'password_confirmation' => $password])->assertRedirect('/login');
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check($password, $contact->fresh()->password));
        $this->assertSame(2, $contact->fresh()->auth_version);
        $this->assertDatabaseCount('account_onboarding_tokens', 0);$this->assertFalse($g->fresh()->active);
        $this->get('https://staging.example.test'.$path)->assertForbidden();
    }

    public function test_validation_preserves_criteria_labels_and_employer_reply_choice(): void
    {
        [$admin,$t,$a,$contact,$g] = $this->fixture();
        $data = ['cohort' => 'FICTIONAL-KEEP-COHORT', 'source_reference' => '', 'criteria' => $this->criteria(), 'confirmed' => 1];
        $this->actingAs($t)->from(route('gateway.show', $a))->post(route('gateway.prepare', $a), $data)->assertRedirect();
        $this->get(route('gateway.show', $a))->assertSee('FICTIONAL-KEEP-COHORT')->assertSee('Fictional sourced tasks criterion');
        $x = $this->publish($t, $a, $g);
        $this->actingAs($contact)->from(route('employer-actions.show', $x))->post(route('employer-actions.respond',$x),['version' => 1, 'response' => 'Cannot confirm', 'note' => '', 'confirmed' => 1])->assertRedirect();
        $this->get(route('employer-actions.show',$x))->assertSee('selected>Cannot confirm',false);
    }
}
