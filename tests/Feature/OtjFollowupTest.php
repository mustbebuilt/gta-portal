<?php

namespace Tests\Feature;

use App\Models\ProgressReviewDraft;
use App\Models\SmartAction;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\CreatesOperationalPlan;
use Tests\TestCase;

class OtjFollowupTest extends TestCase
{
    use LazilyRefreshDatabase, CreatesOperationalPlan;

    public function test_caseload_guides_pending_checks_and_carries_one_open_catchup_into_review(): void
    {
        [$a, $admin, , $tutor, $learner] = $this->approvedFixture();
        $report = '/visual-reports?apprentice='.$a->id;
        $actions = '/apprentices/'.$a->id.'/actions';
        $pending = TrainingSession::factory()->create(['members'=>[$a->id], 'session_date'=>today()->subDay()]);
        $this->actingAs($tutor)->get($report)->assertOk()->assertSee('Check pending OTJ records')->assertDontSee('Agree catch-up');
        $pending->state = 'Cancelled';
        $pending->save();
        $this->get($report)->assertOk()->assertSee('Agree catch-up')->assertDontSee('Check pending OTJ records');
        $this->get($actions.'/create?otj=1')->assertOk()->assertSee('value="otj" selected', false);
        $this->assertDatabaseCount('smart_actions', 0);

        $due = today()->addDays(7)->toDateString();
        $payload = [
            'title'=>'Complete two supervised diagnostic sessions', 'owner'=>'Tutor', 'due_date'=>$due,
            'method'=>'outcomes', 'criteria'=>'Two recorded sessions checked by the tutor', 'observable'=>1,
            'evidence_method'=>'observation', 'relevance'=>SmartAction::OTJ_CATCHUP,
            'learning_reference'=>'Off-the-job training catch-up', 'support'=>'Tutor to agree sessions with the workplace mentor',
            'achievable'=>1, 'agreed'=>1,
        ];
        $this->post($actions,$payload)->assertSessionHasNoErrors();
        $action = SmartAction::sole();
        $this->post($actions,$payload)->assertSessionHasErrors('relevance');
        $this->assertDatabaseCount('smart_actions', 1);
        $this->get($report)->assertOk()->assertSee('View existing action')->assertDontSee('Agree catch-up');
        $this->get($actions.'/create?otj=1')->assertRedirect(url($actions).'#action-'.$action->id);
        $this->actingAs($learner)->get($report)->assertOk()->assertDontSee('OTJ follow-up');
        $this->get($actions.'/create?otj=1')->assertForbidden();
        $this->actingAs($this->newActor('tutor'))->get($report)->assertNotFound();
        $this->get($actions.'/create?otj=1')->assertForbidden();

        $reviews = '/apprentices/'.$a->id.'/reviews';
        $this->actingAs($admin)->post($reviews,['fields'=>['summary'=>'Fictional follow-up review']])->assertSessionHasNoErrors();
        $review = ProgressReviewDraft::sole();
        $this->assertSame($due, $review->source_snapshot['action_plan'][0]['due_date']);
        $this->get($reviews.'/'.$review->id)->assertOk()->assertSee('Open OTJ catch-up actions')->assertSee($action->title);
        $this->actingAs($tutor)->post('/actions/'.$action->id.'/progress', [
            'version'=>$action->version, 'intent'=>'complete', 'completion_date'=>today()->toDateString(),
            'feedback'=>'Tutor confirmed both records.',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Completed', $action->fresh()->status);
        $this->get($report)->assertOk()->assertDontSee('View existing action')->assertSee('Agree catch-up');
        $this->actingAs($admin)->get($reviews.'/'.$review->id)->assertOk()->assertSee('Open OTJ catch-up actions');
        $this->post($reviews,['fields'=>['summary'=>'Later review']])->assertSessionHasNoErrors();
        $later = ProgressReviewDraft::latest('id')->firstOrFail();
        $this->get($reviews.'/'.$later->id)->assertOk()->assertDontSee('Open OTJ catch-up actions');
    }
}
