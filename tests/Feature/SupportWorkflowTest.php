<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\SupportConcern;
use App\Models\SupportPlan;
use App\Models\TeachingInstruction;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupportWorkflowTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role = 'admin'): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->save();

        return $u;
    }

    private function classified(): array
    {
        $u = $this->actor('inclusion_lead');
        $a = Apprentice::factory()->create();
        $c = SupportConcern::factory()->create(['apprentice_id' => $a->id]);
        $this->actingAs($u)->post('/concerns/'.$c->id.'/classify', ['version' => 1, 'code' => 'IM36', 'owner_id' => $u->id, 'next_action' => 'Check travel arrangements', 'action_due' => today()->addDays(2)->toDateString(), 'next_review' => today()->addDays(7)->toDateString()])->assertRedirect()->assertSessionHasNoErrors();

        return [$c->fresh(), $a, $u];
    }

    private function planData(int $id): array
    {
        return ['concern_ids' => [$id], 'fields' => ['type' => 'full', 'referral' => today()->toDateString(), 'barriers' => 'Fictional travel barrier', 'strengths' => 'Engages well in practical work', 'view' => 'Wants to attend consistently', 'support' => 'Discuss reliable transport options', 'review' => today()->addDays(28)->toDateString(), 'funding' => 'not_funded']];
    }

    public function test_tutor_raises_without_codes_and_lead_acknowledges_assigns(): void
    {
        $u = $this->actor('tutor');
        $a = Apprentice::factory()->create(['tutor_id' => $u->id]);
        $this->actingAs($u)->post('/apprentices/'.$a->id.'/support', ['initial_note' => 'Fictional travel concern'])->assertRedirect()->assertSessionHasNoErrors();
        $c = SupportConcern::firstOrFail();
        $this->assertNull($c->code);
        $this->assertNull($c->acknowledged_at);
        $this->post('/concerns/'.$c->id.'/classify', [])->assertForbidden();
        $this->get('/apprentices/'.$a->id.'/support')->assertOk()->assertSee('Fictional travel concern');
    }

    public function test_cannot_raise_for_unassigned_apprentice_or_self_allocate_marker(): void
    {
        $a = Apprentice::factory()->create();
        $this->actingAs($this->actor('tutor'))->post('/apprentices/'.$a->id.'/support', ['initial_note' => 'Wrong scope'])->assertForbidden();
        $this->actingAs($this->actor())->post('/apprentices/'.$a->id.'/support', ['initial_note' => 'Concern', 'code' => 'IM36'])->assertSessionHasErrors('code');
    }

    public function test_members_cannot_classify_and_private_notes_are_excluded_from_tutor_receipt(): void
    {
        [$c,$a,$lead] = $this->classified();
        $tutor = $this->actor('tutor');
        $a->tutor_id = $tutor->id;
        $a->save();
        $c->raised_by = $tutor->id;
        $c->next_action = 'Private team action text';
        $c->save();
        $this->actingAs($this->actor('inclusion_member'))->post('/concerns/'.$c->id.'/classify', [])->assertForbidden();
        $this->actingAs($tutor)->get('/apprentices/'.$a->id.'/support')->assertOk()->assertDontSee('Private team action text')->assertDontSee('IM36');
        $this->get('/support')->assertForbidden();
    }

    public function test_apprentice_and_employer_have_no_private_support_access(): void
    {
        [$c,$a] = $this->classified();
        foreach (['apprentice', 'employer'] as $role) {
            $u = $this->actor($role);
            if ($role === 'apprentice') {
                $a->user_id = $u->id;
                $a->save();
            }$this->actingAs($u)->get('/apprentices/'.$a->id.'/support')->assertForbidden();
            $this->get('/support')->assertForbidden();
            $this->post('/concerns/'.$c->id.'/followup', [])->assertForbidden();
        }
    }

    public function test_multiple_markers_keep_descriptions_and_stale_edit_protection(): void
    {
        $a = Apprentice::factory()->create();
        $this->actingAs($this->actor())->post('/apprentices/'.$a->id.'/markers', ['version' => 1, 'codes' => ['IM1', 'IM29', 'IM36'], 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(3, DB::table('apprentice_markers')->where('apprentice_id', $a->id)->count());
        $this->get('/apprentices/'.$a->id.'/support')->assertOk()->assertSee('Dyslexia')->assertSee('Apprentice Support Plan required.');
        $this->post('/apprentices/'.$a->id.'/markers', ['version' => 1, 'codes' => ['IM18'], 'confirmed' => 1])->assertSessionHasErrors('version');
    }

    public function test_prefer_not_to_say_does_not_create_plan_requirement(): void
    {
        $a = Apprentice::factory()->create();
        $this->actingAs($this->actor())->post('/apprentices/'.$a->id.'/markers', ['version' => 1, 'codes' => ['IM18', 'IM28'], 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/apprentices/'.$a->id.'/support')->assertOk()->assertSee('No marker currently creates a support-plan requirement.');
    }

    public function test_weekly_followup_is_chronological_and_needs_measurable_target(): void
    {
        [$c,$a,$u] = $this->classified();
        $data = ['version' => 2, 'date' => today()->toDateString(), 'note' => 'Discussed options', 'target' => 'Try the agreed travel route', 'measure' => 'Record arrival at the next two sessions', 'owner' => 'Apprentice and tutor', 'due_date' => today()->addDays(14)->toDateString(), 'next_review' => today()->addDays(7)->toDateString(), 'outcome' => 'More support needed', 'agreed' => 1];
        $this->actingAs($u)->post('/concerns/'.$c->id.'/followup', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertCount(1, $c->fresh()->followups);
        $this->get('/apprentices/'.$a->id.'/support')->assertOk()->assertSee('Record arrival at the next two sessions');
        $this->post('/concerns/'.$c->id.'/followup', $data)->assertSessionHasErrors('version');
    }

    public function test_approved_teaching_instruction_shares_only_released_text_with_named_current_tutor(): void
    {
        $lead = $this->actor('inclusion_lead');
        $tutor = $this->actor('tutor');
        $a = Apprentice::factory()->create(['tutor_id' => $tutor->id]);
        $this->actingAs($lead)->post('/apprentices/'.$a->id.'/instructions', ['draft_text' => 'Provide a written task sequence', 'private_note' => 'Private case note must never be released', 'recipient_ids' => [$tutor->id]])->assertRedirect()->assertSessionHasNoErrors();
        $i = TeachingInstruction::firstOrFail();
        $this->actingAs($tutor)->get('/apprentices/'.$a->id.'/instructions')->assertOk()->assertDontSee('Provide a written task sequence')->assertDontSee('Private case note must never be released');
        $this->actingAs($lead)->post('/instructions/'.$i->id.'/release', ['version' => 1, 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($tutor)->get('/apprentices/'.$a->id.'/instructions')->assertOk()->assertSee('Provide a written task sequence')->assertDontSee('Private case note must never be released');
        $this->post('/instructions/'.$i->id.'/acknowledge', ['version' => 2, 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertArrayHasKey((string) $tutor->id, $i->fresh()->acknowledgements);
        $a->tutor_id = $this->actor('tutor')->id;
        $a->save();
        $this->get('/apprentices/'.$a->id.'/instructions')->assertForbidden();
    }

    public function test_member_draft_needs_lead_approval_and_cannot_target_unlinked_staff(): void
    {
        $member = $this->actor('inclusion_member');
        $a = Apprentice::factory()->create();
        $unlinked = $this->actor('tutor');
        $this->actingAs($member)->post('/apprentices/'.$a->id.'/instructions', ['draft_text' => 'Practical guidance', 'recipient_ids' => [$unlinked->id]])->assertSessionHasErrors('recipient_ids.0');
        $a->tutor_id = $unlinked->id;
        $a->save();
        $this->post('/apprentices/'.$a->id.'/instructions', ['draft_text' => 'Practical guidance', 'recipient_ids' => [$unlinked->id]])->assertRedirect()->assertSessionHasNoErrors();
        $i = TeachingInstruction::firstOrFail();
        $this->post('/instructions/'.$i->id.'/release', ['version' => 1, 'confirmed' => 1])->assertForbidden();
    }

    public function test_private_support_plan_draft_approval_and_review_keep_active_version_separate(): void
    {
        [$c,$a,$u] = $this->classified();
        $this->actingAs($u)->get('/apprentices/'.$a->id.'/support-plans/new')->assertOk();
        $this->post('/apprentices/'.$a->id.'/support-plans', $this->planData($c->id))->assertRedirect()->assertSessionHasNoErrors();
        $p = SupportPlan::firstOrFail();
        $this->assertNull($p->active_plan);
        $this->post('/support-plans/'.$p->id.'/approve', ['version' => 1, 'reason' => 'Checked with apprentice', 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Active', $p->fresh()->status);
        $approved = $p->fresh()->active_plan;
        $updated = $this->planData($c->id);
        $updated['fields']['support'] = 'Proposed different support';
        $this->post('/apprentices/'.$a->id.'/support-plans/'.$p->id, [...$updated, 'version' => 2])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($approved, $p->fresh()->active_plan);
        $this->post('/support-plans/'.$p->id.'/review', ['version' => 3, 'date' => today()->toDateString(), 'baseline' => 'Attendance barrier', 'delivered' => 'Travel planning discussion', 'impact' => 'Next steps agreed', 'evidence' => 'Apprentice feedback', 'direction' => 'continue', 'next' => today()->addDays(28)->toDateString()])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertCount(1, $p->fresh()->reviews);
        $this->get('/apprentices/'.$a->id.'/support-plans/'.$p->id)->assertOk()->assertSee('Travel planning discussion');
    }

    public function test_unclassified_other_apprentice_and_disclosure_only_concerns_cannot_be_plan_sources(): void
    {
        [$c,$a,$u] = $this->classified();
        $other = SupportConcern::factory()->create();
        $this->actingAs($u)->post('/apprentices/'.$a->id.'/support-plans', $this->planData($other->id))->assertSessionHasErrors('concern_ids.0');
        $c->code = 'IM18';
        $c->save();
        $this->post('/apprentices/'.$a->id.'/support-plans', $this->planData($c->id))->assertSessionHasErrors('concern_ids.0');
    }

    public function test_changed_code_blocks_approval_until_checked_draft_is_saved(): void
    {
        [$c,$a,$u] = $this->classified();
        $this->actingAs($u)->post('/apprentices/'.$a->id.'/support-plans', $this->planData($c->id));
        $p = SupportPlan::firstOrFail();
        $c->code = 'IM38';
        $c->save();
        $this->post('/support-plans/'.$p->id.'/approve', ['version' => 1, 'reason' => 'Changed source', 'confirmed' => 1])->assertSessionHasErrors('fields');
        $this->assertNull($p->fresh()->active_plan);
    }

    public function test_closed_plan_is_immutable_and_does_not_generate_funding_or_otj(): void
    {
        [$c,$a,$u] = $this->classified();
        $this->actingAs($u)->post('/apprentices/'.$a->id.'/support-plans', $this->planData($c->id));
        $p = SupportPlan::firstOrFail();
        $this->post('/support-plans/'.$p->id.'/approve', ['version' => 1, 'reason' => 'Checked', 'confirmed' => 1]);
        $this->post('/support-plans/'.$p->id.'/close', ['version' => 2, 'outcome' => 'Support goals achieved', 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/apprentices/'.$a->id.'/support-plans/'.$p->id, [...$this->planData($c->id), 'version' => 3])->assertSessionHasErrors('fields');
        $this->assertDatabaseCount('attendance_marks', 0);
        $this->assertSame('Closed', $p->fresh()->status);
    }

    public function test_current_plan_cannot_be_duplicated_and_als_needs_rationale_before_approval(): void
    {
        [$c,$a,$u] = $this->classified();
        $data = $this->planData($c->id);
        $data['fields']['funding'] = 'als_funded';
        $this->actingAs($u)->post('/apprentices/'.$a->id.'/support-plans', $data)->assertRedirect()->assertSessionHasNoErrors();
        $p = SupportPlan::firstOrFail();
        $this->post('/apprentices/'.$a->id.'/support-plans', $data)->assertSessionHasErrors('fields');
        $this->post('/support-plans/'.$p->id.'/approve', ['version' => 1, 'reason' => 'Check funding', 'confirmed' => 1])->assertSessionHasErrors('fields');
        $this->assertDatabaseCount('support_plans', 1);
        $this->assertNull($p->fresh()->active_plan);
    }
}
