<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\LearningTask;
use App\Models\SmartAction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SmartActionWorkflowTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role = 'admin'): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->save();

        return $u;
    }

    private function data(): array
    {
        return ['title' => 'Complete five brake-system questions', 'owner' => 'Apprentice', 'due_date' => today()->addDays(14)->toDateString(), 'method' => 'quantity', 'quantity' => 5, 'unit' => 'questions', 'evidence_method' => 'worksheet', 'relevance' => 'block', 'learning_reference' => 'Block 1 brake systems', 'support' => 'Tutor session available', 'achievable' => 1, 'agreed' => 1];
    }

    public function test_smart_creation_builds_measure_and_reopens_saved_target(): void
    {
        $a = Apprentice::factory()->create();
        $this->actingAs($this->actor())->get('/apprentices/'.$a->id.'/actions/create')->assertOk();
        $this->post('/apprentices/'.$a->id.'/actions', $this->data())->assertRedirect()->assertSessionHasNoErrors();
        $row = SmartAction::firstOrFail();
        $this->assertSame('5 questions', $row->measure);
        $this->assertSame('To do', $row->status);
        $this->get('/apprentices/'.$a->id.'/actions')->assertOk()->assertSee('5 questions');
        $this->get('/apprentices/'.$a->id.'/actions/'.$row->id.'/edit')->assertOk();
    }

    public function test_missing_measures_agreement_support_and_deadline_are_rejected(): void
    {
        $a = Apprentice::factory()->create();
        $this->actingAs($this->actor());
        $data = $this->data();
        unset($data['quantity'],$data['agreed'],$data['support'],$data['due_date']);
        $this->post('/apprentices/'.$a->id.'/actions', $data)->assertSessionHasErrors(['quantity', 'agreed', 'support']);
        $this->assertDatabaseCount('smart_actions', 0);
        $data = $this->data();
        $data['due_date'] = today()->subDay()->toDateString();
        $this->post('/apprentices/'.$a->id.'/actions', $data)->assertSessionHasErrors('due_date');
    }

    public function test_outcome_targets_need_observable_confirmation(): void
    {
        $a = Apprentice::factory()->create();
        $data = [...$this->data(), 'method' => 'outcomes', 'criteria' => 'Record actual pressures against manufacturer specifications.'];
        $this->actingAs($this->actor())->post('/apprentices/'.$a->id.'/actions', $data)->assertSessionHasErrors('observable');
        $this->post('/apprentices/'.$a->id.'/actions', [...$data, 'observable' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($data['criteria'], SmartAction::firstOrFail()->measure);
    }

    public function test_manual_action_flows_to_tutor_and_completion_requires_tutor_evidence(): void
    {
        $l = $this->actor('apprentice');
        $u = $this->actor('tutor');
        $a = Apprentice::factory()->create(['user_id' => $l->id, 'tutor_id' => $u->id]);
        $row = SmartAction::factory()->create(['apprentice_id' => $a->id]);
        $url = '/actions/'.$row->id.'/progress';
        $this->actingAs($l)->post($url, ['version' => 1, 'intent' => 'submit', 'evidence_note' => 'Worksheet stored with tutor.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('With tutor', $row->fresh()->status);
        $this->post($url, ['version' => 2, 'intent' => 'complete'])->assertForbidden();
        $this->actingAs($u)->post($url, ['version' => 2, 'intent' => 'complete', 'completion_date' => '2026-11-01', 'feedback' => 'Checked all five answers against the completion criteria.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Completed', $row->fresh()->status);
        $this->assertCount(2, $row->fresh()->history);
    }

    public function test_return_to_apprentice_preserves_deadline_and_evidence(): void
    {
        $row = SmartAction::factory()->create();
        $row->status = 'With tutor';
        $row->evidence_note = 'Original evidence';
        $row->save();
        $date = $row->due_date->toDateString();
        $this->actingAs($this->actor())->post('/actions/'.$row->id.'/progress', ['version' => 1, 'intent' => 'return', 'feedback' => 'Please correct question 2.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('To do', $row->fresh()->status);
        $this->assertSame('Original evidence', $row->fresh()->evidence_note);
        $this->assertSame($date, $row->fresh()->due_date->toDateString());
    }

    public function test_linked_actions_follow_task_status_and_date_without_duplicate_decisions(): void
    {
        $task = LearningTask::factory()->create(['due_date' => '2026-11-01']);
        $task->status = 'Referred';
        $task->save();
        $a = $task->apprentice;
        $this->actingAs($this->actor());
        $data = [...$this->data(), 'method' => 'linked-task', 'learning_task_id' => $task->id, 'due_date' => '2099-01-01'];
        $this->post('/apprentices/'.$a->id.'/actions', $data)->assertRedirect()->assertSessionHasNoErrors();
        $row = SmartAction::firstOrFail();
        $this->assertNull($row->due_date);
        $this->assertSame('2026-11-01', $row->deadline()->toDateString());
        $task->status = 'Awaiting marking';
        $task->save();
        $this->assertSame('With tutor', $row->fresh()->currentStatus());
        $task->status = 'Approved';
        $task->save();
        $this->assertSame('Completed', $row->fresh()->currentStatus());
        $this->post('/actions/'.$row->id.'/progress', ['version' => 1, 'intent' => 'complete'])->assertSessionHasErrors('intent');
        $this->post('/apprentices/'.$a->id.'/actions', $data)->assertSessionHasErrors('learning_task_id');
        $this->assertDatabaseCount('smart_actions', 1);
    }

    public function test_other_apprentice_task_cannot_be_linked(): void
    {
        $a = Apprentice::factory()->create();
        $task = LearningTask::factory()->create();
        $task->status = 'Referred';
        $task->save();
        $this->actingAs($this->actor())->post('/apprentices/'.$a->id.'/actions', [...$this->data(), 'method' => 'linked-task', 'learning_task_id' => $task->id])->assertSessionHasErrors('learning_task_id');
    }

    public function test_employer_and_unassigned_tutor_cannot_read_or_create_actions(): void
    {
        $a = Apprentice::factory()->create();
        foreach (['employer', 'tutor', 'careers_adviser'] as $role) {
            $this->actingAs($this->actor($role))->get('/apprentices/'.$a->id.'/actions')->assertForbidden();
            $this->post('/apprentices/'.$a->id.'/actions', $this->data())->assertForbidden();
        }
    }

    public function test_apprentice_cannot_create_targets_or_submit_tutor_owned_work(): void
    {
        $l = $this->actor('apprentice');
        $a = Apprentice::factory()->create(['user_id' => $l->id]);
        $row = SmartAction::factory()->create(['apprentice_id' => $a->id, 'owner' => 'Tutor']);
        $this->actingAs($l)->post('/apprentices/'.$a->id.'/actions', $this->data())->assertForbidden();
        $this->post('/actions/'.$row->id.'/progress', ['version' => 1, 'intent' => 'submit', 'evidence_note' => 'False'])->assertForbidden();
    }

    public function test_stale_edit_and_nested_record_tampering_are_rejected(): void
    {
        $row = SmartAction::factory()->create();
        $other = Apprentice::factory()->create();
        $this->actingAs($this->actor());
        $url = '/apprentices/'.$row->apprentice_id.'/actions/'.$row->id;
        $this->put($url, [...$this->data(), 'version' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->put($url, [...$this->data(), 'version' => 1])->assertSessionHasErrors('version');
        $this->put('/apprentices/'.$other->id.'/actions/'.$row->id, [...$this->data(), 'version' => 2])->assertNotFound();
    }

    public function test_overdue_filter_uses_open_source_deadline_and_completed_is_blue(): void
    {
        $this->travelTo(Carbon::parse('2026-11-11'));
        $row = SmartAction::factory()->create(['due_date' => '2026-11-01']);
        $this->assertTrue($row->overdue());
        $this->actingAs($this->actor())->get('/apprentices/'.$row->apprentice_id.'/actions?due=overdue')->assertOk()->assertSee('Overdue');
        $row->status = 'Completed';
        $row->save();
        $this->assertFalse($row->fresh()->overdue());
        $this->get('/apprentices/'.$row->apprentice_id.'/actions?due=overdue')->assertOk()->assertSee('No matching actions');
    }
}
