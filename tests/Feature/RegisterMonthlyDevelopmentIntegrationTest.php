<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\AttendanceMark;
use App\Models\LearningTask;
use App\Models\MonthlyScorecard;
use App\Models\Programme;
use App\Models\ProgressReviewDraft;
use App\Models\TrainingSession;
use App\Models\TutorGroup;
use App\Models\User;
use App\Support\ApprenticeReviewData;
use App\Support\MonthlyDevelopment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class RegisterMonthlyDevelopmentIntegrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_register_task_and_review_journey_includes_monthly_development(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 17:00:00'));
        $tutor = User::factory()->create(['role'=>'tutor','active'=>true]);
        $learner = User::factory()->create(['role'=>'apprentice','active'=>true]);
        $admin = User::factory()->create(['role'=>'admin','active'=>true]);
        $programme = Programme::factory()->create(['standard_code'=>'ST0068','version'=>'1.5']);
        $group = TutorGroup::factory()->create(['tutor_id'=>$tutor->id]);
        $apprentice = Apprentice::factory()->create([
            'programme_id'=>$programme->id,'tutor_id'=>$tutor->id,'user_id'=>$learner->id,
            'tutor_group_id'=>$group->id,'start_date'=>'2026-10-01','planned_end_date'=>'2029-10-01',
        ]);

        $session = TrainingSession::factory()->create([
            'tutor_group_id'=>$group->id,'members'=>[$apprentice->id],'session_date'=>'2026-11-19',
            'start_time'=>'09:00','end_time'=>'16:00','breaks'=>[['start'=>'12:00','end'=>'13:00']]
        ]);
        $ratings = array_fill_keys(array_keys(ApprenticeReviewData::DEVELOPMENT_AREAS),'Developing');
        $urlRegister = '/sessions/'.$session->id;
        $this->actingAs($tutor)->get($urlRegister)->assertOk()->assertSee('Monthly personal development')->assertSee('Tutor comment (optional)');
        $register = [
            'version'=>1,'delivered_learning'=>'Fictional vehicle inspection workshop',
            'marks'=>[$apprentice->id=>['code'=>'O','arrival'=>'09:00','departure'=>'16:00','otj_confirmed'=>1]]
        ];
        $regSnapshot = ['enabled'=>1,'version'=>0,'ratings'=>$ratings,'comment'=>'Fictional November observation'];
        $this->post($urlRegister, $register + ['development'=>[$apprentice->id=>array_replace($regSnapshot, ['ratings'=>[]])]])
            ->assertSessionHasErrors('development.'.$apprentice->id.'.ratings.engagement');
        $this->assertDatabaseCount('attendance_marks', 0);
        $absent = array_replace($register, ['marks'=>[$apprentice->id=>['code'=>'A']]]);
        $this->post($urlRegister, $absent + ['development'=>[$apprentice->id=>$regSnapshot]])
            ->assertSessionHasErrors('development');
        $this->assertDatabaseCount('attendance_marks', 0);
        $this->assertDatabaseCount('monthly_scorecards', 0);
        $this->post($urlRegister, $register + ['development'=>[$apprentice->id=>$regSnapshot]])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(360, AttendanceMark::where('training_session_id',$session->id)->firstOrFail()->otj_minutes);
        $this->get('/apprentices/'.$apprentice->id.'/attendance')->assertOk()->assertSee('100%')->assertSee('6.00 hrs');

        $monthly = MonthlyScorecard::where('apprentice_id',$apprentice->id)->sole();
        $this->assertSame('Fictional November observation', MonthlyDevelopment::read($monthly)['comment']);
        $this->get('/apprentices/'.$apprentice->id)->assertOk()->assertSee('Fictional November observation');
        $this->get('/apprentices/'.$apprentice->id.'/monthly-snapshot?month=2026-11')
            ->assertOk()->assertSee('Fictional November observation')->assertSee('Developing');

        $later = ['enabled'=>1,'version'=>$monthly->version,'ratings'=>$ratings,'comment'=>'Edited November observation'];
        $register['version'] = $session->fresh()->version;
        $this->post($urlRegister, $register + ['development'=>[$apprentice->id=>$later]])
            ->assertSessionHasNoErrors();
        $this->assertSame($register['version'], $session->fresh()->version);
        $this->assertDatabaseCount('monthly_scorecards', 1);
        $this->post($urlRegister, $register + ['development'=>[$apprentice->id=>$later]])
            ->assertSessionHasErrors('development');
        $this->assertSame('Edited November observation', MonthlyDevelopment::read($monthly->fresh())['comment']);

        $this->actingAs($admin)->post('/apprentices/'.$apprentice->id.'/learning',['confirmed'=>1])->assertSessionHasNoErrors();
        $task = LearningTask::where('apprentice_id',$apprentice->id)->where('reference','heavy-vehicle-b1-t3')->firstOrFail();
        $name = collect($task->template['fields'])->firstWhere('key','apprentice_name')['name'];
        $url = '/tasks/'.$task->id;
        $this->actingAs($learner)->post($url,['version'=>1,'intent'=>'submit','own_work'=>1,'answers'=>[$name=>'Fictional apprentice']])->assertSessionHasNoErrors();
        $this->actingAs($tutor)->post($url.'/decision',['version'=>2,'decision'=>'Referred','decision_date'=>'2026-11-20','feedback'=>'Add the inspection reading.','confirmed'=>1])->assertSessionHasNoErrors();
        $this->actingAs($learner)->get($url)->assertOk()->assertSee('Add the inspection reading.');
        $this->post($url,['version'=>3,'intent'=>'submit','own_work'=>1,'answers'=>[$name=>'Fictional revised submission']])->assertSessionHasNoErrors();
        $this->actingAs($tutor)->post($url.'/decision',['version'=>4,'decision'=>'Approved','decision_date'=>'2026-11-20','confirmed'=>1])->assertSessionHasNoErrors();
        $this->assertCount(2,$task->fresh()->submissions);
        $this->assertSame('Referred',$task->fresh()->submissions[0]['decision']['status']);
        $this->assertSame('Approved',$task->fresh()->submissions[1]['decision']['status']);
        $this->assertSame(1,LearningTask::where('apprentice_id',$apprentice->id)->where('status','Approved')->count());

        $this->actingAs($admin)->post('/apprentices/'.$apprentice->id.'/reviews',[
            'fields'=>['reviewDate'=>'2026-11-20','trainingFrom'=>'2026-10-01','summary'=>'Fictional test review']
        ])->assertRedirect()->assertSessionHasNoErrors();
        $draft = ProgressReviewDraft::where('apprentice_id',$apprentice->id)->firstOrFail();
        $snapshot = $draft->source_snapshot;
        $this->assertSame(360,$snapshot['otj_position']['recorded_minutes']);
        $this->assertSame(1,$snapshot['approved_tasks']);
        $this->assertSame(50,$snapshot['assigned_tasks']);
        $this->assertSame('Fictional vehicle inspection workshop',$snapshot['delivered'][0]['subjects']);
        $this->assertSame('2027-01-29',$draft->fields['nextReview']);
        $this->assertSame(['month'=>'2026-10','missing'=>true], $snapshot['monthly_snapshots'][0]);
        $this->assertSame('2026-11',$snapshot['monthly_snapshots'][1]['month']);
        $this->assertSame('Developing',$snapshot['monthly_snapshots'][1]['development']['ratings']['engagement']);
        $this->assertSame('Edited November observation',$snapshot['monthly_snapshots'][1]['development']['comment']);
        $reviewUrl = '/apprentices/'.$apprentice->id.'/reviews/'.$draft->id;
        $this->get($reviewUrl)->assertOk()->assertSee('Edited November observation')->assertSee('Developing');
        $this->actingAs($tutor)->post($urlRegister, $register + ['development'=>[
            $apprentice->id=>array_replace($later, ['version'=>$monthly->fresh()->version,'comment'=>'Later observation'])
        ]])->assertSessionHasNoErrors();
        $this->actingAs($admin)->get($reviewUrl)->assertSee('Edited November observation')->assertDontSee('Later observation');
    }
}
