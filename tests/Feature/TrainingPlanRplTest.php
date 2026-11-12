<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\TrainingPlanWorkspace as Workspace;
use App\Models\User;
use App\Support\TrainingPlanSource as Source;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class TrainingPlanRplTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role = 'admin'): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->save();

        return $u;
    }

    private function values(int $version = 0, array $changes = []): array
    {
        return [...['version' => $version, 'code' => 'K1', 'outcome' => 'zero', 'omission' => Source::OMISSIONS[0]], ...$changes];
    }

    private function complete(Apprentice $a, User $u): Workspace
    {
        $this->actingAs($u);
        $version = 0;
        foreach (Source::load('training-plan-reference')['ksbs'] as $k) {
            $this->post('/apprentices/'.$a->id.'/training-plan/rpl', $this->values($version, ['code' => $k['code']]))->assertRedirect()->assertSessionHasNoErrors();
            $version++;
        }

        return Workspace::where('apprentice_id', $a->id)->firstOrFail();
    }

    public function test_draft_proposal_saves_reopens_without_applying_dates_or_target(): void
    {
        $a = Apprentice::factory()->create(['start_date' => '2026-01-05', 'planned_end_date' => null]);
        $u = $this->actor();
        $url = '/apprentices/'.$a->id.'/training-plan';
        $this->actingAs($u)->post($url.'/proposal', ['version' => 0, 'programmeRef' => Source::load('training-plan-reference')['id'], 'practicalEnd' => '2028-06-01', 'otjHours' => '600', 'basis' => 'Manual planning proposal'])->assertRedirect()->assertSessionHasNoErrors();
        $w = Workspace::firstOrFail();
        $this->assertSame('600', $w->proposal['otjHours']);
        $this->assertNull($a->fresh()->planned_end_date);
        $this->get($url)->assertOk()->assertSee('600')->assertSee('Not an approved individual training plan');
        $this->assertDatabaseCount('attendance_marks', 0);
    }

    public function test_proposal_requires_programme_basis_valid_date_positive_hours_and_current_version(): void
    {
        $a = Apprentice::factory()->create(['start_date' => '2026-01-05']);
        $this->actingAs($this->actor());
        $url = '/apprentices/'.$a->id.'/training-plan/proposal';
        $ref = Source::load('training-plan-reference')['id'];
        $this->post($url, ['version' => 0, 'otjHours' => 600, 'basis' => 'Programme baseline'])->assertSessionHasErrors('programmeRef');
        $this->post($url, ['version' => 0, 'programmeRef' => $ref, 'otjHours' => 600])->assertSessionHasErrors('basis');
        $this->post($url, ['version' => 0, 'programmeRef' => $ref, 'otjHours' => 0, 'basis' => 'Programme baseline'])->assertSessionHasErrors('otjHours');
        $this->post($url, ['version' => 0, 'programmeRef' => $ref, 'practicalEnd' => '2025-12-01', 'basis' => 'Programme baseline'])->assertSessionHasErrors('practicalEnd');
        $this->post($url, ['version' => 0, 'programmeRef' => $ref])->assertRedirect()->assertSessionHasNoErrors();
        $this->post($url, ['version' => 0, 'programmeRef' => $ref])->assertSessionHasErrors('version');
    }

    public function test_missing_rpl_outcome_is_unknown_and_exposure_retains_training_without_totals(): void
    {
        $a = Apprentice::factory()->create();
        $this->actingAs($this->actor());
        $url = '/apprentices/'.$a->id.'/training-plan';
        $this->post($url.'/rpl', ['version' => 0, 'code' => 'K1', 'omission' => Source::OMISSIONS[0]])->assertSessionHasErrors('outcome');
        $this->post($url.'/rpl', $this->values(0, ['outcome' => 'exposure']))->assertRedirect()->assertSessionHasNoErrors();
        $w = Workspace::firstOrFail();
        $p = Source::position($w);
        $this->assertSame(1, $p['recorded']);
        $this->assertNull($p['remaining_hours']);
        $this->assertNull($p['allocation_reduction']);
        $this->get($url)->assertOk()->assertSee('1 of 64 source KSB decisions recorded')->assertSee('totals remain unknown');
    }

    public function test_reduction_needs_specific_evidence_and_matching_partial_or_full_omission(): void
    {
        $a = Apprentice::factory()->create();
        $this->actingAs($this->actor());
        $url = '/apprentices/'.$a->id.'/training-plan/rpl';
        $this->post($url, $this->values(0, ['outcome' => '50', 'omission' => Source::OMISSIONS[2]]))->assertSessionHasErrors('omission');
        $v = $this->values(0, ['outcome' => '50', 'source' => 'Direct observation/practical check', 'evidence' => 'Fictional observation reference', 'content' => 'Specific knowledge explanation and practical checks', 'omission' => Source::OMISSIONS[3]]);
        $this->post($url, $v)->assertSessionHasErrors('omission');
        $this->post($url, [...$v, 'omission' => Source::OMISSIONS[2]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(50, Source::percent(Workspace::firstOrFail()->rpl['K1']));
    }

    public function test_zero_outcome_cannot_omit_training_and_unknown_code_cannot_be_added(): void
    {
        $a = Apprentice::factory()->create();
        $this->actingAs($this->actor());
        $url = '/apprentices/'.$a->id.'/training-plan/rpl';
        $this->post($url, $this->values(0, ['content' => 'Omit something']))->assertSessionHasErrors('omission');
        $this->post($url, $this->values(0, ['code' => 'K999']))->assertSessionHasErrors('code');
        $this->assertDatabaseCount('training_plan_workspaces', 0);
    }

    public function test_only_admin_ceo_assess_and_own_apprentice_main_tutor_receive_safe_projection(): void
    {
        $tutor = $this->actor('tutor');
        $learner = $this->actor('apprentice');
        $a = Apprentice::factory()->create(['tutor_id' => $tutor->id, 'user_id' => $learner->id]);
        $admin = $this->actor();
        $url = '/apprentices/'.$a->id.'/training-plan';
        $this->actingAs($admin)->post($url.'/rpl', $this->values(0, ['evidence' => 'Private assessor reference never public']))->assertRedirect()->assertSessionHasNoErrors();
        foreach ([$tutor, $learner] as $u) {
            $this->actingAs($u)->get($url)->assertOk()->assertDontSee('Private assessor reference never public')->assertDontSee('rpl_review_recorded')->assertDontSee('Internal draft planning calculation');
            $this->post($url.'/rpl', $this->values(1))->assertForbidden();
        }
        foreach (['employer', 'mentor', 'careers_adviser', 'functional_skills_tutor'] as $role) {
            $this->actingAs($this->actor($role))->get($url)->assertForbidden();
        }
        $this->actingAs($this->actor('tutor'))->get($url)->assertForbidden();
    }

    public function test_incomplete_assessment_cannot_be_approved_or_circumvented_by_posted_totals(): void
    {
        $a = Apprentice::factory()->create();
        $u = $this->actor();
        $url = '/apprentices/'.$a->id.'/training-plan';
        $this->actingAs($u)->post($url.'/rpl', $this->values())->assertRedirect()->assertSessionHasNoErrors();
        $w = Workspace::firstOrFail();
        $this->post($url.'/rpl-review', ['version' => 1, 'binding' => Source::position($w)['binding'], 'decision' => 'approve', 'assessment_checked' => 1, 'omissions_checked' => 1, 'complete' => true, 'individual_target' => 0])->assertSessionHasErrors('decision');
        $this->assertNull($w->fresh()->reviews);
    }

    public function test_complete_assessment_approval_is_separate_from_plan_application_and_source_change_invalidates_it(): void
    {
        $a = Apprentice::factory()->create();
        $u = $this->actor('ceo');
        $w = $this->complete($a, $u);
        $p = Source::position($w);
        $this->assertTrue($p['complete']);
        $this->assertEquals(663, $p['remaining_hours']);
        $this->assertEquals(7150, $p['remaining_allocation']);
        $url = '/apprentices/'.$a->id.'/training-plan';
        $data = ['version' => $w->version, 'binding' => $p['binding'], 'decision' => 'approve', 'assessment_checked' => 1, 'omissions_checked' => 1];
        $this->post($url.'/rpl-review', $data)->assertRedirect()->assertSessionHasNoErrors();
        $w = $w->fresh();
        $this->assertTrue(Source::position($w)['approved']);
        $this->assertSame([], $w->proposal);
        $this->assertDatabaseCount('learning_tasks', 0);
        $this->assertDatabaseCount('attendance_marks', 0);
        $this->post($url.'/rpl', $this->values($w->version, ['code' => 'K1', 'outcome' => 'exposure']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse(Source::position($w->fresh())['approved']);
        $this->get($url)->assertOk()->assertSee('Assessment changed');
    }

    public function test_return_requires_reason_and_current_approval_must_be_withdrawn_before_return(): void
    {
        $a = Apprentice::factory()->create();
        $u = $this->actor();
        $w = $this->complete($a, $u);
        $url = '/apprentices/'.$a->id.'/training-plan/rpl-review';
        $this->post($url, ['version' => $w->version, 'binding' => Source::position($w)['binding'], 'decision' => 'return'])->assertSessionHasErrors(['reason', 'code']);
        $this->post($url, ['version' => $w->version, 'binding' => Source::position($w)['binding'], 'decision' => 'approve', 'assessment_checked' => 1, 'omissions_checked' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $w = $w->fresh();
        $data = ['version' => $w->version, 'binding' => Source::position($w)['binding'], 'reason' => 'evidence', 'code' => 'K1'];
        $this->post($url, [...$data, 'decision' => 'return'])->assertSessionHasErrors('decision');
        $this->post($url, [...$data, 'decision' => 'withdraw'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse(Source::position($w->fresh())['approved']);
        $this->assertCount(2, $w->fresh()->reviews);
    }

    public function test_stale_binding_source_and_source_hash_mismatch_cannot_overwrite_saved_assessments(): void
    {
        $a = Apprentice::factory()->create();
        $this->actingAs($this->actor());
        $url = '/apprentices/'.$a->id.'/training-plan';
        $this->post($url.'/rpl', $this->values())->assertRedirect()->assertSessionHasNoErrors();
        $w = Workspace::firstOrFail();
        $this->post($url.'/rpl', $this->values(0, ['code' => 'K2']))->assertSessionHasErrors('version');
        $this->post($url.'/rpl-review', ['version' => 1, 'binding' => str_repeat('0', 64), 'decision' => 'return', 'reason' => 'evidence', 'code' => 'all'])->assertSessionHasErrors('binding');
        $w->source_hash = str_repeat('0', 64);
        $w->save();
        $this->post($url.'/rpl', $this->values(1))->assertSessionHasErrors('version');
        $this->get($url)->assertOk()->assertSee('controlled reconciliation');
        $this->assertSame('zero', $w->fresh()->rpl['K1']['outcome']);
    }

    public function test_resaving_unchanged_assessment_does_not_clear_a_changes_needed_review(): void
    {
        $a = Apprentice::factory()->create();
        $u = $this->actor();
        $w = $this->complete($a, $u);
        $url = '/apprentices/'.$a->id.'/training-plan';
        $this->post($url.'/rpl-review', ['version' => $w->version, 'binding' => Source::position($w)['binding'], 'decision' => 'return', 'reason' => 'evidence', 'code' => 'K1'])->assertRedirect()->assertSessionHasNoErrors();
        $w = $w->fresh();
        $beforeVersion = $w->version;
        $this->post($url.'/rpl', $this->values($w->version))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($beforeVersion, $w->fresh()->version);
        $this->post($url.'/rpl-review', ['version' => $w->version, 'binding' => Source::position($w)['binding'], 'decision' => 'approve', 'assessment_checked' => 1, 'omissions_checked' => 1])->assertSessionHasErrors('decision');
    }

    public function test_invalid_saved_assessor_version_or_future_timestamp_is_not_approval_ready(): void
    {
        $a = Apprentice::factory()->create();
        $u = $this->actor();
        $w = $this->complete($a, $u);
        $records = $w->rpl;
        $records['K1']['revision'] = 0;
        $records['K2']['at'] = now()->addDay()->toIso8601String();
        $w->rpl = $records;
        $w->save();
        $p = Source::position($w);
        $this->assertFalse($p['complete']);
        $this->assertNull($p['remaining_hours']);
        $this->get('/apprentices/'.$a->id.'/training-plan')->assertOk()->assertSee('K1 needs checking')->assertSee('K2 needs checking');
    }
}
