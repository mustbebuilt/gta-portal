<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\ProgrammeOutcome;
use App\Models\SupportConcern;
use App\Models\TrainingSession;
use App\Models\User;
use App\Support\RegisterTraining;
use App\Support\ReviewSnapshot;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ConsolidatedPortalPackTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_reports_directory_and_retention_drilldown_preserve_permissions_and_totals(): void
    {
        $admin = User::factory()->create(['role'=>'admin']);
        $apprentice = Apprentice::factory()->create(['start_date'=>'2025-09-01','planned_end_date'=>'2027-09-01']);
        ProgrammeOutcome::create(['apprentice_id'=>$apprentice->id,'context'=>[
            'status'=>'Withdrawn','endDate'=>'2026-10-01','sourceRef'=>'Test record',
        ]]);
        $this->actingAs($admin)->get(route('reports.index'))->assertOk()
            ->assertSeeInOrder(['Who needs attention?', 'Support and Retention reporting', 'How are apprentices progressing?', 'How are we performing?', 'Retention and Early Leaver Report'])->assertSee('Employer attendance patterns');
        $report = $this->get(route('retention.report', ['outcome'=>'early-leavers','from'=>'2025-09-01']))->assertOk();
        $this->assertSame(1, $report->viewData('headlines')['early-leavers']);
        $this->assertSame($report->viewData('headlines')['early-leavers'], $report->viewData('rows')->total());
        $this->actingAs(User::factory()->create(['role'=>'tutor']))->get(route('reports.index'))->assertOk()
            ->assertDontSee('Retention and Early Leaver Report')->assertDontSee('Support and Retention reporting')->assertDontSee('How are we performing?')->assertDontSee('Employer attendance patterns');
        $this->get(route('support.report'))->assertForbidden();
    }

    public function test_formal_marker_review_advances_only_the_selected_marker(): void
    {
        $this->travelTo(now()->setDate(2026,11,21));
        $admin = User::factory()->create(['role'=>'admin']);
        $person = Apprentice::factory()->create();
        $this->actingAs($admin);
        foreach (['IM34','IM36'] as $code) {
            $this->post(route('support.track-marker',$person), ['code'=>$code,'frequency'=>'monthly'])
                ->assertRedirect()->assertSessionHasNoErrors();
        }
        $first = SupportConcern::where('code','IM34')->firstOrFail();
        $second = SupportConcern::where('code','IM36')->firstOrFail();
        $this->get(route('support.index',$person))->assertOk()->assertSee('Save review');
        $this->post(route('support.marker-review',$first), [
            'version'=>$first->version,'date'=>'2026-11-21','frequency'=>'monthly','outcome'=>'Private review text',
            'action'=>'Contact employer', 'owner'=>$admin->id, 'due'=>'2026-11-24',
        ])->assertSessionHasNoErrors();
        $this->assertSame('2026-12-21', $first->fresh()->next_review->toDateString());
        $this->assertNull($second->fresh()->next_review);
        $register = $this->get(route('support.worklist',['view'=>'needs-review']))->assertOk()
            ->assertDontSee('Private review text')->assertSee('Contact employer');
        $this->assertSame([$second->id], $register->viewData('rows')->items()[0]['due']->pluck('id')->all());
        $this->get(route('support.report'))->assertOk()->assertDontSee('Private review text');
        $this->post(route('support.complete-action',$first), [
            'version'=>$first->fresh()->version, 'outcome'=>'Reached employer',
        ])->assertSessionHasNoErrors();
        $this->assertNull($first->fresh()->action_due);
        $this->assertSame('2026-12-21', $first->fresh()->next_review->toDateString());
    }

    public function test_recorded_training_flows_from_register_to_personal_record_and_review(): void
    {
        $this->travelTo(now()->setDate(2026,11,21));
        $admin = User::factory()->create(['role'=>'admin']);
        $person = Apprentice::factory()->create(['start_date'=>'2026-09-01']);
        $session = TrainingSession::factory()->create(['session_date'=>'2026-11-01','members'=>[$person->id]]);
        $this->actingAs($admin)->get(route('attendance.show',$session))->assertOk()->assertSee('Training delivered to the group');
        $this->post(route('attendance.save',$session), [
            'version'=>$session->version,'weekly_confirmed'=>1,
            'training'=>['vocational'=>'Workshop inspection','functional_skills'=>'Reading instructions',
                'exceptions'=>[$person->id=>['functional_skills'=>'partial']]],
            'marks'=>[$person->id=>['code'=>'O','arrival'=>'09:00','departure'=>'16:00']],
        ])->assertSessionHasNoErrors();
        $receipts = collect(RegisterTraining::receipts($person->id))->keyBy('area');
        $this->assertSame('Workshop inspection',$receipts['vocational']['label']);
        $this->assertSame('partial',$receipts['functional_skills']['coverage']);
        $this->assertDatabaseCount('monthly_scorecards',0);
        $snapshot = ReviewSnapshot::capture($person,['trainingFrom'=>'2026-10-01','reviewDate'=>'2026-11-05']);
        $this->assertSame(1, $snapshot['review_summary']['attendance']['attended']);
        $this->assertCount(2, $snapshot['review_summary']['training_receipts']);
        $this->get(route('apprentices.show',$person))->assertOk()->assertSee('Workshop inspection');
    }
}
