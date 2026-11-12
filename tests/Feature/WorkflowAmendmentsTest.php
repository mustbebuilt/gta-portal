<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\CareerDiscussion;
use App\Models\TeachingInstruction;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class WorkflowAmendmentsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->save();

        return $u;
    }

    private function careers(): array
    {
        $adviser = $this->actor('careers_adviser');
        $a = Apprentice::factory()->create(['careers_adviser_id' => $adviser->id]);
        $d = CareerDiscussion::factory()->create(['apprentice_id' => $a->id, 'adviser_id' => $adviser->id]);

        return [$a, $d, $adviser];
    }

    private function discussionData(array $changes = []): array
    {
        return [...['version' => 1, 'type' => 'progression', 'discussion_date' => '2026-11-01', 'goals' => 'Fictional revised goal', 'options' => 'Further training', 'advice' => 'Compare options', 'outcome' => 'Exploring options', 'next_step' => 'Check course details'], ...$changes];
    }

    private function actionData(array $changes = []): array
    {
        return [...['version' => 1, 'title' => 'Compare two technical courses', 'owner' => 'Apprentice', 'due_date' => today()->addDays(14)->toDateString(), 'criteria' => 'List entry requirements and delivery for both courses', 'support' => 'Adviser guidance', 'observable' => 1, 'achievable' => 1, 'agreed' => 1], ...$changes];
    }

    private function instruction(): array
    {
        $lead = $this->actor('inclusion_lead');
        $tutor = $this->actor('tutor');
        $a = Apprentice::factory()->create(['tutor_id' => $tutor->id]);
        $this->actingAs($lead)->post('/apprentices/'.$a->id.'/instructions', ['draft_text' => 'Original released guidance', 'private_note' => 'Never tutor visible', 'recipient_ids' => [$tutor->id]])->assertRedirect()->assertSessionHasNoErrors();
        $i = TeachingInstruction::firstOrFail();
        $this->post('/instructions/'.$i->id.'/release', ['version' => 1, 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();

        return [$a, $i->fresh(), $lead, $tutor];
    }

    public function test_discussion_amendment_retains_original_adviser_and_previous_terms(): void
    {
        [$a,$d,$old] = $this->careers();
        $new = $this->actor('careers_adviser');
        $a->careers_adviser_id = $new->id;
        $a->save();
        $this->actingAs($new)->get('/apprentices/'.$a->id.'/careers?edit='.$d->id)->assertOk()->assertSee('Amend careers discussion');
        $this->post('/apprentices/'.$a->id.'/careers/'.$d->id, $this->discussionData())->assertRedirect()->assertSessionHasNoErrors();
        $saved = $d->fresh();
        $this->assertSame($old->id, $saved->adviser_id);
        $this->assertSame('Fictional revised goal', $saved->goals);
        $this->assertSame($d->goals, $saved->history[0]['previous']['goals']);
        $this->get('/apprentices/'.$a->id.'/careers')->assertOk()->assertSee('Amendment history');
        $this->actingAs($old)->post('/apprentices/'.$a->id.'/careers/'.$d->id, $this->discussionData(['version' => 2]))->assertForbidden();
    }

    public function test_discussion_date_cannot_pass_followup_and_stale_cannot_overwrite(): void
    {
        [$a,$d,$u] = $this->careers();
        $d->followups = [['date' => '2026-11-02']];
        $d->save();
        $this->actingAs($u)->post('/apprentices/'.$a->id.'/careers/'.$d->id, $this->discussionData(['discussion_date' => '2026-11-03']))->assertSessionHasErrors('discussion_date');
        $this->post('/apprentices/'.$a->id.'/careers/'.$d->id, $this->discussionData(['version' => 0]))->assertSessionHasErrors('version');
        $this->assertSame(1, $d->fresh()->version);
    }

    public function test_cross_apprentice_and_nonwriter_cannot_amend_discussion(): void
    {
        [$a,$d] = $this->careers();
        $other = Apprentice::factory()->create();
        $this->actingAs($this->actor('admin'))->post('/apprentices/'.$other->id.'/careers/'.$d->id, $this->discussionData())->assertNotFound();
        $learner = $this->actor('apprentice');
        $a->user_id = $learner->id;
        $a->save();
        $this->actingAs($learner)->get('/apprentices/'.$a->id.'/careers?edit='.$d->id)->assertForbidden();
        $this->post('/apprentices/'.$a->id.'/careers/'.$d->id, $this->discussionData())->assertForbidden();
    }

    public function test_only_to_do_careers_action_can_be_revised_without_duplication(): void
    {
        [$a,$d,$u] = $this->careers();
        $url = '/career-discussions/'.$d->id.'/actions';
        $this->actingAs($u)->post($url, $this->actionData())->assertRedirect()->assertSessionHasNoErrors();
        $action = $d->fresh()->actions[0];
        $this->post($url, $this->actionData(['version' => 2, 'action_id' => $action['id'], 'title' => 'Compare three courses']))->assertRedirect()->assertSessionHasNoErrors();
        $saved = $d->fresh();
        $this->assertCount(1, $saved->actions);
        $this->assertSame($action['title'], $saved->history[0]['previous']['title']);
        $actions = $saved->actions;
        $actions[0]['status'] = 'Completed';
        $saved->actions = $actions;
        $saved->save();
        $this->post($url, $this->actionData(['version' => 3, 'action_id' => $action['id']]))->assertSessionHasErrors('action_id');
    }

    public function test_careers_worklist_filters_original_type_due_owner_and_current_scope(): void
    {
        [$a,$d,$u] = $this->careers();
        $this->actingAs($u)->post('/career-discussions/'.$d->id.'/actions', $this->actionData())->assertRedirect()->assertSessionHasNoErrors();
        $actions = $d->fresh()->actions;
        $actions[0]['due_date'] = today()->subDay()->toDateString();
        $actions[0]['status'] = 'Ready for adviser check';
        $d->actions = $actions;
        $d->save();
        $this->get('/careers?show=overdue&type=progression')->assertOk()->assertSee('Compare two technical courses')->assertSee('Responsible now: '.$u->name)->assertSee('work_show=overdue', false);
        $this->get('/careers?type=employment')->assertOk()->assertDontSee('Compare two technical courses');
        $this->actingAs($this->actor('careers_adviser'))->get('/careers')->assertOk()->assertDontSee('Compare two technical courses');
        $this->actingAs($this->actor('employer'))->get('/careers')->assertForbidden();
    }

    public function test_instruction_amendment_keeps_published_copy_until_new_approval(): void
    {
        [$a,$i,$lead,$tutor] = $this->instruction();
        $this->actingAs($tutor)->post('/instructions/'.$i->id.'/acknowledge', ['version' => 2, 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($lead)->post('/instructions/'.$i->id.'/amend', ['version' => 3, 'draft_text' => 'Revised teaching sequence', 'private_note' => 'Still never tutor visible', 'recipient_ids' => [$tutor->id]])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($tutor)->get('/apprentices/'.$a->id.'/instructions')->assertOk()->assertSee('Original released guidance')->assertDontSee('Revised teaching sequence')->assertDontSee('Never tutor visible');
        $this->actingAs($lead)->post('/instructions/'.$i->id.'/release', ['version' => 4, 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $saved = $i->fresh();
        $this->assertSame(2, $saved->draft_revision);
        $this->assertSame([], $saved->acknowledgements);
        $this->assertSame('Original released guidance', $saved->history[1]['release']['text']);
        $this->actingAs($tutor)->get('/apprentices/'.$a->id.'/instructions')->assertOk()->assertSee('Revised teaching sequence')->assertDontSee('Original released guidance');
    }

    public function test_instruction_withdrawal_removes_tutor_access_and_preserves_reason(): void
    {
        [$a,$i,$lead,$tutor] = $this->instruction();
        $url = '/instructions/'.$i->id.'/withdraw';
        $this->actingAs($lead)->post($url, ['version' => 2, 'release_version' => 1])->assertSessionHasErrors('reason');
        $this->post($url, ['version' => 2, 'release_version' => 1, 'reason' => 'Guidance replaced by individual reassessment'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($tutor)->get('/apprentices/'.$a->id.'/instructions')->assertOk()->assertDontSee('Original released guidance');
        $this->post('/instructions/'.$i->id.'/acknowledge', ['version' => 3, 'confirmed' => 1])->assertForbidden();
        $this->assertSame('Guidance replaced by individual reassessment', $i->fresh()->release['withdrawal_reason']);
    }

    public function test_instruction_amendment_permissions_stale_and_recipient_changes_are_enforced(): void
    {
        [$a,$i,$lead,$tutor] = $this->instruction();
        $url = '/instructions/'.$i->id;
        $data = ['version' => 2, 'draft_text' => 'Draft', 'recipient_ids' => [$tutor->id]];
        $this->actingAs($this->actor('admin'))->post($url.'/amend', $data)->assertForbidden();
        $this->actingAs($this->actor('inclusion_member'))->post($url.'/withdraw', ['version' => 2, 'release_version' => 1, 'reason' => 'Test'])->assertForbidden();
        $this->actingAs($lead)->post($url.'/amend', [...$data, 'version' => 1])->assertSessionHasErrors('version');
        $tutor->active = false;
        $tutor->save();
        $this->post($url.'/amend', $data)->assertSessionHasErrors('recipient_ids.0');
    }

    public function test_instruction_team_view_renders_amendment_and_withdraw_controls(): void
    {
        [$a,$i,$lead] = $this->instruction();
        $this->actingAs($lead)->get('/apprentices/'.$a->id.'/support')->assertOk()->assertSee('Amend instruction draft')->assertSee('Withdraw approved tutor copy');
    }
}
