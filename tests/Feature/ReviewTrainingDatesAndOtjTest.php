<?php

namespace Tests\Feature;

use App\Models\ExtraTraining;
use App\Models\ProgressReviewDraft;
use App\Models\TrainingPlanWorkspace;
use App\Support\DeliveryPlan;
use App\Support\OtjPosition;
use App\Support\TrainingPlanSource as Source;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\CreatesOperationalPlan;
use Tests\TestCase;

class ReviewTrainingDatesAndOtjTest extends TestCase
{
    use LazilyRefreshDatabase, CreatesOperationalPlan;

    public function test_review_reads_agreed_dates_and_revised_target_and_live_report_tracks_pace(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 16:00'));
        [$a,$admin,$ceo,,,,$plan] = $this->approvedFixture();
        $records = [];
        foreach (Source::load('training-plan-reference')['ksbs'] as $ksb) {
            $records[$ksb['code']] = [
                'outcome'=>'50', 'source'=>Source::EVIDENCE[0], 'evidence'=>'Synthetic assessment',
                'content'=>'Synthetic evidence of prior learning', 'omission'=>Source::OMISSIONS[2],
                'revision'=>1, 'by'=>$ceo->id, 'at'=>now()->subDay()->toIso8601String(),
            ];
        }
        $workspace = new TrainingPlanWorkspace;
        $workspace->forceFill(['apprentice_id'=>$a->id,'source_hash'=>Source::hash(),'rpl'=>$records,'proposal'=>[]])->save();
        $pos = Source::position($workspace);
        $this->assertTrue($pos['complete']);
        $workspace->reviews = [['binding'=>$pos['binding'],'status'=>'Assessment approved']];
        $workspace->save();
        $this->assertTrue(Source::position($workspace)['approved']);
        $approved = $plan->approved;
        // Seed the two explicit date fields using the Pack 69 confirmation format.
        $approved['details']['practical_end_date'] = $approved['blocks'][10]['end'];
        $approved['details']['apprenticeship_end_date'] = $approved['blocks'][11]['end'];
        $approved['details']['target_hours'] = $pos['remaining_hours'];
        $approved['details']['basis'] = 'Current Autocare RPL assessment';
        $approved['rpl_binding'] = $pos['binding'];
        foreach ($approved['blocks'] as &$block) {
            $block['hours'] = $block['number'] === 12 ? 0 : ($block['number'] === 11 ? 31.5 : 30);
        }
        unset($block);
        $plan->approved = $approved;
        $plan->save();
        $this->assertNotNull(DeliveryPlan::approved($a));

        $summary = OtjPosition::forApprentice($a)['summary'];
        $this->assertEquals(331.5, $summary['target_hours']);
        $this->assertEquals(300, $summary['progressive_expected_hours']);
        $this->travel(10)->days();
        $later = OtjPosition::forApprentice($a)['summary'];
        $this->assertEquals(315.75, $later['progressive_expected_hours']);
        $this->assertEquals(300, $later['planned_due_hours']);
        ExtraTraining::factory()->create(['apprentice_id'=>$a->id,'approval'=>['by'=>$admin->id]]);
        $this->assertEquals(313.75, OtjPosition::forApprentice($a)['summary']['progressive_gap_hours']);
        $reviewEndpoint = '/apprentices/'.$a->id.'/reviews';
        $this->actingAs($admin)->post($reviewEndpoint,['fields'=>['summary'=>'Fictional review']])->assertSessionHasNoErrors();
        $review = ProgressReviewDraft::firstOrFail();
        $this->assertSame([
            'start'=>$a->start_date->toDateString(),
            'practical_end'=>$approved['details']['practical_end_date'],
            'apprenticeship_end'=>$approved['details']['apprenticeship_end_date'],
        ], $review->source_snapshot['programme_dates']);
        $this->get($reviewEndpoint.'/'.$review->id)->assertOk()->assertSee('Expected practical finish date')
            ->assertSee('331.50 hours')->assertSee('313.75 hours below pace');
        $this->get('/visual-reports?apprentice='.$a->id)->assertOk()->assertSee('313.75 hrs below pace');
        $this->travel(10)->days();
        $this->assertEquals(331.5, OtjPosition::forApprentice($a)['summary']['progressive_expected_hours']);
        $this->assertEquals(315.75, $review->fresh()->source_snapshot['otj_position']['progressive_expected_hours']);

        $workspace->reviews = [['binding'=>'no longer current','status'=>'Assessment approved']];
        $workspace->save();
        $this->assertNull(OtjPosition::forApprentice($a)['summary']['progressive_expected_hours']);
    }
}
