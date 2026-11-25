<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\Employer;
use App\Models\EmployerApprenticeGrant;
use App\Models\EmployerPortalAction;
use App\Models\ExtraTraining;
use App\Models\GatewayReadinessPlan;
use App\Models\Programme;
use App\Models\ProgrammeOutcome;
use App\Models\ProgressReviewDraft;
use App\Models\SmartAction;
use App\Models\User;
use App\Support\EmployerActions;
use App\Support\GatewayReadiness;
use App\Support\GatewaySummary;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\ConfiguresStaging;
use Tests\TestCase;

class GatewaySummary57Test extends TestCase
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

    private function learner($a): User
    {
        $u = User::factory()->create(['role' => 'apprentice']);
        $a->user_id = $u->id;
        $a->save();

        return $u;
    }

    private function approved(): array
    {
        [$admin,$t,$a,$contact,$g] = $this->fixture();
        $u = $this->learner($a);
        $p = $this->plan($t, $a);
        $this->approve($admin, $p);

        return [$admin, $t, $a, $u, $p->fresh()];
    }

    public function test_missing_criteria_never_become_zero_of_zero_complete(): void
    {
        [$admin,$t,$a] = $this->fixture();
        $u = $this->learner($a);
        $summary = GatewaySummary::for($u, $a);
        $this->assertFalse($summary['current']);
        $this->assertFalse($summary['all_met']);
        $this->assertSame([], $summary['preview']);
        $this->actingAs($u)->get(route('experience.home'))->assertOk()->assertSee('Gateway preparation is not assessed')->assertDontSee('0 of 0 configured');
        $this->get(route('gateway.show', $a))->assertOk()->assertDontSee('0 of 0 configured')->assertSee('independent approval');
    }

    public function test_draft_criteria_are_not_a_current_preparation_summary(): void
    {
        [$admin,$t,$a] = $this->fixture();
        $u = $this->learner($a);
        $this->plan($t, $a);
        $this->assertFalse(GatewaySummary::for($u, $a)['current']);
    }

    public function test_missing_checks_are_prioritised_before_unknown_and_preview_is_bounded(): void
    {
        [$admin,$t,$a,$u,$p] = $this->approved();
        $d = $this->checkData($p, 'c5');
        $d['state'] = 'Missing';
        $d['note'] = 'PRIVATE-NOTE-SUMMARY57';
        $d['evidence_reference'] = 'PRIVATE-REF-SUMMARY57';
        GatewayReadiness::check($t, 1, $p, $d);
        $s = GatewaySummary::for($u, $a);
        $this->assertTrue($s['current']);
        $this->assertSame(['Met' => 0, 'Missing' => 1, 'Unknown' => 5], $s['counts']);
        $this->assertCount(3, $s['preview']);
        $this->assertCount(6, $s['outstanding']);
        $this->assertSame('Missing', $s['preview'][0]['state']);
        $this->assertStringEndsWith('#gateway-criterion-c5', $s['preview'][0]['url']);
        $this->assertStringNotContainsString('PRIVATE-', json_encode($s));
        $this->actingAs($u)->get(route('experience.home'))->assertOk()->assertSee('3 more outstanding check(s)')->assertDontSee('PRIVATE-NOTE-SUMMARY57')->assertDontSee('PRIVATE-REF-SUMMARY57');
    }

    public function test_exact_section_links_exist_on_full_page_and_remain_gated(): void
    {
        [$admin,$t,$a,$u,$p] = $this->approved();
        $this->actingAs($u)->get(route('gateway.show', $a))->assertOk()->assertSee('id="gateway-criterion-c1"', false)->assertSee(route('help.index'), false);
        $other = User::factory()->create(['role' => 'apprentice']);
        $this->actingAs($other)->get(route('gateway.show', $a))->assertForbidden();
    }

    public function test_all_configured_checks_met_still_requires_separate_gateway_review(): void
    {
        [$admin,$t,$a,$u,$p] = $this->approved();
        foreach (range(1, 6) as $i) {
            GatewayReadiness::check($t, 1, $p, $this->checkData($p, 'c'.$i));
        }$s = GatewaySummary::for($u, $a);
        $this->assertTrue($s['all_met']);
        $this->assertSame(6, $s['counts']['Met']);
        $this->assertSame([], $s['outstanding']);
        $this->actingAs($u)->get(route('experience.home'))->assertOk()->assertSee('provider must still review')->assertDontSee('Gateway approved');
    }

    public function test_source_drift_withholds_stale_criteria(): void
    {
        [$admin,$t,$a,$u,$p] = $this->approved();
        $a->update(['start_date' => today()->subDays(90)]);
        $this->assertFalse(GatewaySummary::for($u, $a)['current']);
        $this->actingAs($u)->get(route('experience.home'))->assertOk()->assertSee('not assessed here yet');
    }

    public function test_withdrawn_criteria_are_not_presented_as_current(): void
    {
        [$admin,$t,$a,$u,$p] = $this->approved();
        GatewayReadiness::change($admin, 1, $p, ['version' => $p->version, 'state' => 'Withdrawn', 'note' => 'Fictional correction']);
        $this->assertFalse(GatewaySummary::for($u, $a)['current']);
    }

    public function test_summary_escapes_recorded_criterion_labels(): void
    {
        [$admin,$t,$a] = $this->fixture();
        $u = $this->learner($a);
        $criteria = $this->criteria();
        $criteria[0]['label'] = '<script>CRITERION57</script>';
        $p = GatewayReadiness::prepare($t, 1, $a, ['cohort' => 'Fictional57', 'source_reference' => 'Fictional reviewed source57', 'criteria' => $criteria]);
        $this->approve($admin, $p);
        $this->actingAs($u)->get(route('experience.home'))->assertOk()->assertSee('&lt;script&gt;CRITERION57&lt;/script&gt;', false)->assertDontSee('<script>CRITERION57</script>', false);
    }

    public function test_reads_leave_checks_plans_outcomes_and_audits_unchanged(): void
    {
        Mail::fake();
        Http::preventStrayRequests();
        [$admin,$t,$a,$u,$p] = $this->approved();
        $tables = ['gateway_readiness_plans', 'gateway_readiness_checks', 'programme_outcomes', 'audit_events'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->count();
        }$this->actingAs($u)->get(route('experience.home'))->assertOk();
        $this->get(route('journey.show', $a))->assertOk();
        $this->get(route('gateway.show', $a))->assertOk();
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->count());
        }Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_unrelated_staff_employer_and_apprentice_cannot_capture_summary(): void
    {
        [$admin,$t,$a,$u] = $this->approved();
        foreach (['employer', 'tutor', 'apprentice', 'careers_adviser'] as $role) {
            $x = User::factory()->create(['role' => $role]);
            try {
                GatewaySummary::for($x, $a);
                $this->fail('Expected403');
            } catch (HttpException$e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
    }

    public function test_gateway_help_is_current_and_scoped_without_management_duties_for_learner(): void
    {
        [$admin,$t,$a,$u] = $this->approved();
        $this->actingAs($u)->get('/help?guide=gateway-preparation')->assertOk()->assertSee('Missing means')->assertSee('All configured checks met does not approve Gateway');
        $x = User::factory()->create(['role' => 'employer']);
        $this->actingAs($x)->get('/help?guide=gateway-preparation')->assertNotFound();
    }
}
