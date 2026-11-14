<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\LearningTask;
use App\Models\SmartAction;
use App\Support\ActionProposals;
use App\Support\TaskCatalogue;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesOperationalPlan;
use Tests\TestCase;

class ActionProposalTest extends TestCase
{
    use LazilyRefreshDatabase,CreatesOperationalPlan;

    private function referred($a): LearningTask
    {
        $t=LearningTask::factory()->create(['apprentice_id'=>$a->id,'reference'=>'b1-t4','template'=>TaskCatalogue::template('b1-t4'),'due_date'=>today()->addDays(7)]);$t->status='Referred';$t->submissions=[['decision'=>['feedback'=>'Fictional corrections: add actual measurements.']]];$t->save();return $t;
    }

    private function payload($t): array
    {
        return ['source_task_id'=>$t->id,'source_binding'=>ActionProposals::binding($t),'title'=>'Agreed correction action','owner'=>'Apprentice','method'=>'linked-task','learning_task_id'=>$t->id,'evidence_method'=>'portal','relevance'=>'correction','learning_reference'=>$t->reference,'support'=>'Tutor demonstration available','achievable'=>1,'agreed'=>1];
    }

    public function test_proposal_reads_do_not_create_targets_and_confirmation_creates_one_linked_action(): void
    {
        [$a,$admin,$ceo,$tutor]=$this->approvedFixture();$t=$this->referred($a);$base='/apprentices/'.$a->id.'/actions';
        $this->actingAs($tutor)->get($base.'/proposals')->assertOk()->assertSee('Fictional corrections')->assertSee('Prepare correction action');
        $this->get($base.'/create?source_task_id='.$t->id)->assertOk()->assertSee('No agreement box is preselected')->assertSee('Correct and resubmit');
        $this->assertDatabaseCount('smart_actions',0);$data=$this->payload($t);unset($data['agreed']);
        $this->post($base,$data)->assertSessionHasErrors('agreed');
        $this->post($base,$this->payload($t))->assertSessionHasNoErrors();$action=SmartAction::firstOrFail();
        $this->assertSame($t->id,$action->learning_task_id);$this->assertNull($action->due_date);$this->assertSame($t->due_date->toDateString(),$action->deadline()->toDateString());
        $this->assertSame(ActionProposals::binding($t),$action->history[0]['proposal_source']['binding']);
        $this->post($base,$this->payload($t))->assertSessionHasErrors('learning_task_id');$this->assertDatabaseCount('smart_actions',1);
        $this->get($base.'/proposals')->assertSee('Open existing action');
    }

    public function test_stale_decision_source_or_missing_deadline_does_not_create_an_action(): void
    {
        [$a,$admin]=$this->approvedFixture();$t=$this->referred($a);$data=$this->payload($t);$t->version++;$t->save();
        $this->actingAs($admin)->post('/apprentices/'.$a->id.'/actions',$data)->assertSessionHasErrors('source_binding');
        $t->due_date=null;$t->save();$this->get('/apprentices/'.$a->id.'/actions/create?source_task_id='.$t->id)->assertSessionHasErrors('learning_task_id');
        $this->assertDatabaseCount('smart_actions',0);
    }

    public function test_proposal_scope_and_task_identity_are_enforced_and_audit_failure_is_atomic(): void
    {
        [$a,$admin,$ceo,$tutor,$learner,$adviser]=$this->approvedFixture();$t=$this->referred($a);$base='/apprentices/'.$a->id.'/actions';
        foreach([$learner,$adviser,$this->newActor('tutor')] as $u){$this->actingAs($u)->get($base.'/proposals')->assertForbidden();$this->get($base.'/create?source_task_id='.$t->id)->assertForbidden();}
        $other=Apprentice::factory()->create();$foreign=$this->referred($other);
        $this->actingAs($admin)->get($base.'/create?source_task_id='.$foreign->id)->assertNotFound();
        \Tests\Support\AuditFault::install('reject_action_event','learning_events','Fictional audit failure');
        $this->post($base,$this->payload($t))->assertStatus(500);$this->assertDatabaseCount('smart_actions',0);
    }
}
