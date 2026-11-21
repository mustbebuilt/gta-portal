<?php

namespace Tests\Feature;

use App\Models\ProgressReviewDraft;
use App\Models\SmartAction;
use App\Models\TrainingSession;
use App\Models\TutorGroup;
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
    public function test_pending_sources_open_the_record_only_for_authorised_staff(): void
    {
        [$a, $admin, , $tutor, $learner] = $this->approvedFixture();
        $group = TutorGroup::factory()->create(['tutor_id' => $tutor->id]);
        $other = TutorGroup::factory()->create();
        $dates = ['session_date' => today()->subDay(), 'members' => [$a->id]];
        $owned = TrainingSession::factory()->create($dates + ['tutor_group_id' => $group->id]);
        $external = TrainingSession::factory()->create($dates + ['tutor_group_id' => $other->id]);
        $ledger = '/apprentices/'.$a->id.'/otj-ledger';

        $this->actingAs($tutor)->get('/visual-reports?apprentice='.$a->id)->assertOk()->assertSee($ledger.'#pending-otj', false);
        $this->get($ledger)->assertOk()->assertSee('OTJ records to check')
            ->assertSee(route('attendance.show', $owned), false)
            ->assertDontSee(route('attendance.show', $external), false)
            ->assertSee('Ask an authorised member of staff to check this register.');
        $this->get('/sessions/'.$external->id)->assertForbidden();
        $this->get('/sessions/'.$owned->id)->assertOk();
        $this->post('/sessions/'.$owned->id, [
            'version'=>1, 'delivered_learning'=>'Fictional vehicle inspection',
            'marks'=>[$a->id=>['code'=>'O', 'arrival'=>'09:00', 'departure'=>'16:00', 'otj_confirmed'=>1]],
        ])->assertSessionHasNoErrors();
        $this->get($ledger)->assertOk()->assertDontSee(route('attendance.show', $owned), false);

        $this->actingAs($admin)->get($ledger)->assertOk()->assertSee(route('attendance.show', $external), false);
        $this->actingAs($learner)->get($ledger)->assertOk()->assertDontSee('OTJ records to check')
            ->assertDontSee(route('attendance.show', $external), false);
    }

}
