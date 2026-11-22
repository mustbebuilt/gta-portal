<?php

namespace Tests\Feature;

use App\Models\FormalKsbAssessment;
use App\Models\ProgressReviewDraft;
use App\Models\SupportPlan;
use App\Support\ReviewPreparation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesOperationalPlan;
use Tests\TestCase;

class Pack81ReviewAndSupportCompletionTest extends TestCase
{
    use LazilyRefreshDatabase, CreatesOperationalPlan;

    public function test_review_safety_is_unselected_until_answered_and_needs_private_followup_on_yes(): void
    {
        [$apprentice, , , $tutor, , , $plan] = $this->approvedFixture();
        $this->actingAs($tutor)->get(route('reviews.create', $apprentice))
            ->assertOk()->assertSee('Since your last review, have you had any accidents or near misses?')
            ->assertDontSee('name="fields[safetyAnswer]" value="no" checked', false);
        $this->post(route('reviews.store', $apprentice), ['fields'=>['summary'=>'Test review']])->assertSessionHasNoErrors();
        $review = ProgressReviewDraft::firstOrFail();
        $assessSafety = fn ($record) => collect(ReviewPreparation::inspect($record->fields, $record->source_snapshot, $plan->approved)['items'])
            ->firstWhere('key', 'safety')['checked'];
        $this->assertFalse($assessSafety($review));
        $this->post(route('reviews.update', [$apprentice, $review]), ['version'=>$review->version,
            'fields'=>['safetyAnswer'=>'yes']])->assertSessionHasNoErrors();
        $this->assertFalse($assessSafety($review->refresh()));
        $this->post(route('reviews.update', [$apprentice, $review]), ['version'=>$review->version,
            'fields'=>['safetyAnswer'=>'yes','safetyFollowup'=>'Private incident test reference']])->assertSessionHasNoErrors();
        $this->assertTrue($assessSafety($review->refresh()));
        $this->assertSame('Private incident test reference', $review->fields['safetyFollowup']);
        $this->assertStringNotContainsString('Private incident test reference', json_encode($review->source_snapshot));
        $this->get(route('reviews.pdf', [$apprentice, $review]))->assertOk()->assertHeader('Content-Type','application/pdf');
        $this->post(route('reviews.update', [$apprentice, $review]), ['version'=>$review->version,
            'fields'=>['safetyAnswer'=>'no','safetyFollowup'=>'Discard this on no']])->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('safetyFollowup', $review->fresh()->fields);
    }

    public function test_formal_results_are_source_bound_dated_and_saved_separately_from_task_progress(): void
    {
        [$apprentice, , , $tutor, $learner, , $plan] = $this->approvedFixture();
        $base = ['plan_hash'=>$plan->approved['hash'],'code'=>'k1','outcome'=>'demonstrated',
            'assessed_on'=>today()->subDays(2)->toDateString(),'evidence_reference'=>'Private evidence X321','mapping_checked'=>1];
        $this->actingAs($learner)->post(route('reviews.ksb.store', $apprentice), $base)->assertForbidden();
        $this->actingAs($tutor)->get(route('reviews.ksb.index', $apprentice))->assertOk();
        $this->post(route('reviews.ksb.store', $apprentice), [...$base,'plan_hash'=>str_repeat('a',64)])->assertSessionHasErrors('plan_hash');
        $this->post(route('reviews.ksb.store', $apprentice), $base)->assertSessionHasNoErrors();
        $this->post(route('reviews.ksb.store', $apprentice), [...$base,'code'=>'K1','outcome'=>'further_evidence',
            'assessed_on'=>today()->toDateString()])->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('Private evidence', FormalKsbAssessment::firstOrFail()->getRawOriginal('evidence_reference'));
        $this->post(route('reviews.store', $apprentice), ['fields'=>['reviewDate'=>today()->subDay()->toDateString(),
            'safetyAnswer'=>'no']])->assertSessionHasNoErrors();
        $review = ProgressReviewDraft::firstOrFail();
        $this->assertSame([['code'=>'K1','outcome'=>'Demonstrated','date'=>$base['assessed_on']]],
            $review->source_snapshot['formal_ksb_results']);
        $this->get(route('reviews.edit', [$apprentice,$review]))->assertOk()->assertSee('K1 · Demonstrated')
            ->assertDontSee('Private evidence X321');
        $this->assertDatabaseCount('learning_tasks', 0);
    }

    public function test_private_support_activity_picks_same_plan_objective_and_tracks_followup(): void
    {
        [$apprentice,$admin, , , $learner] = $this->approvedFixture();
        $plan = SupportPlan::create(['apprentice_id'=>$apprentice->id,'concern_ids'=>[],'draft'=>[]]);
        $plan->forceFill(['status'=>'Active','active_plan'=>['approved_at'=>now()->toIso8601String(),
            'fields'=>['support'=>'Support under plan'],'reason'=>'Agreed support']])->save();
        $personPage = route('support.index',$apprentice);
        $this->actingAs($admin)->post(route('support.objectives.store',$plan), ['version'=>$plan->version,
            'objective'=>'Use reading prompts'])->assertSessionHasNoErrors();
        $plan->refresh();
        $objective = array_key_first($plan->activeObjectives());
        $this->assertNotNull($objective);
        $this->get($personPage)->assertOk()->assertSee('Use reading prompts');
        $payload = ['version'=>$plan->version, 'approved_at'=>$plan->active_plan['approved_at'],
            'request_id'=>(string) Str::uuid(),'date'=>today()->toDateString(),'type'=>'Specialist 1:1 Coaching',
            'who'=>(string) $admin->id,'delivered'=>'Practised use of prompts','objective_id'=>$objective,
            'followup_note'=>'Check use in workplace','followup_due'=>today()->addWeek()->toDateString(),'followup_owner'=>$admin->id];
        $this->post(route('support.activity',$plan), [...$payload,'objective_id'=>(string) Str::uuid()])
            ->assertSessionHasErrors('objective_id');
        $this->post(route('support.activity',$plan), [...$payload,'followup_owner'=>$learner->id])
            ->assertSessionHasErrors('followup_owner');
        $this->post(route('support.activity',$plan), $payload)->assertSessionHasNoErrors();
        $activity = $plan->refresh()->activities[0];
        $this->assertSame($objective, $activity['objective']['id']);
        $this->assertSame($admin->id, $activity['followup']['owner_id']);
        $this->get(route('support.plan',[$apprentice,$plan]))->assertOk()->assertSee('Check use in workplace');
        $route = route('support.activity.followup',[$plan,$activity['id']]);
        $completion = ['version'=>$plan->version,'outcome'=>'Prompts used independently'];
        $this->actingAs($learner)->post($route,$completion)->assertForbidden();
        $this->actingAs($admin)->post($route,$completion)->assertSessionHasNoErrors();
        $this->assertSame('Prompts used independently', $plan->refresh()->activities[0]['followup']['outcome']);
        $this->post($route,[...$completion,'version'=>$plan->version])->assertSessionHasErrors('outcome');
    }
}
