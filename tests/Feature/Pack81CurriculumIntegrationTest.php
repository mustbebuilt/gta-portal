<?php

namespace Tests\Feature;

use App\Models\LearningTask;
use App\Models\TrainingSession;
use App\Models\TrainingTopic;
use App\Models\TutorGroup;
use App\Support\DeliveryPlan;
use App\Support\PlanCurriculum;
use App\Support\RegisterTraining;
use App\Support\ReviewSnapshot;
use App\Support\TaskCatalogue;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\CreatesOperationalPlan;
use Tests\TestCase;

class Pack81CurriculumIntegrationTest extends TestCase
{
    use LazilyRefreshDatabase, CreatesOperationalPlan;

    public function test_approved_scope_and_agreed_dates_feed_review_and_catchup_across_groups(): void
    {
        [$apprentice,$admin,$ceo,$tutor,,, $row] = $this->approvedFixture();
        $english = PlanCurriculum::topicKey('english', 'Level 2', 'Purpose');
        $extra = PlanCurriculum::topicKey('english', 'Level 2', 'Text Types');
        $secure = PlanCurriculum::topicKey('english', 'Level 2', 'Spelling');
        $topicFields = ['apprentice_id'=>$apprentice->id, 'subject'=>'english', 'level'=>'Level 2', 'area'=>'Reading'];
        TrainingTopic::create($topicFields + ['topic'=>'Purpose','planned_hours'=>2])->forceFill(['status'=>'Completed'])->save();
        TrainingTopic::create($topicFields + ['topic'=>'Spelling','planned_hours'=>0]);
        $task = LearningTask::factory()->create(['apprentice_id'=>$apprentice->id,'reference'=>'b1-t4','template'=>TaskCatalogue::template('b1-t4')]);
        $task->forceFill(['status'=>'Approved'])->save();

        $data = ['version'=>$row->version,'task_scope'=>'selected','tasks'=>['b1-t3','b1-t4'],
            'subjects'=>['english'=>['scope'=>'selected','topics'=>[$english,$extra],'due'=>today()->subDay()->toDateString()],
                'maths'=>['scope'=>'none']]];
        $this->actingAs($tutor)->post(route('delivery.curriculum',$apprentice), $data)->assertForbidden();
        $this->actingAs($admin)->get(route('delivery.show',$apprentice))->assertOk()->assertSee('Training covered by this plan');
        $this->post(route('delivery.curriculum',$apprentice), ['version'=>$row->version,'task_scope'=>'selected','tasks'=>['paint-b1-t3']])
            ->assertSessionHasErrors('tasks');
        $this->post(route('delivery.curriculum',$apprentice), array_replace_recursive($data,['subjects'=>['english'=>['topics'=>[$secure]]]]))
            ->assertSessionHasErrors('subjects.english.topics');
        $this->post(route('delivery.curriculum',$apprentice), $data)->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('curriculum', DeliveryPlan::approved($apprentice));
        $row->refresh();
        $details = $row->approved['details'];
        $practical = $row->approved['blocks'][10]['end'];
        $end = $row->approved['blocks'][11]['end'];
        $this->post(route('delivery.details',$apprentice), $details + ['version'=>$row->version,
            'practical_end_date'=>$end,'apprenticeship_end_date'=>$practical])->assertSessionHasErrors('practical_end_date');
        $this->post(route('delivery.details',$apprentice), $details + ['version'=>$row->version,
            'practical_end_date'=>$practical,'apprenticeship_end_date'=>$end])->assertSessionHasNoErrors();
        $this->actingAs($ceo)->post(route('delivery.approve',$apprentice), ['version'=>$row->fresh()->version,
            'source_checked'=>1, 'hours_checked'=>1, 'curriculum_checked'=>1, 'release_checked'=>1])->assertSessionHasNoErrors();

        $approved = $row->fresh()->approved;
        $approved['identity'] = array_reverse($approved['identity'], true);
        $row->forceFill(['approved'=>$approved])->save();
        $snapshot = ReviewSnapshot::capture($apprentice, []);
        $progress = $snapshot['review_summary']['progress'];
        $this->assertEquals(100, $progress['Apprenticeship tasks']['percent']);
        $this->assertEquals(50, $progress['Apprenticeship tasks']['whole_percent']);
        $this->assertEquals(50, $progress['English']['whole_percent']);
        $this->assertSame(0, $progress['Maths']['whole_total']);
        $this->assertSame($practical, $snapshot['programme_dates']['practical_end']);
        $this->assertSame($end, $snapshot['programme_dates']['apprenticeship_end']);

        $previousGroup = TutorGroup::factory()->create();
        $newGroup = TutorGroup::factory()->create();
        $apprentice->tutor_group_id = $previousGroup->id;
        $apprentice->save();
        $session = TrainingSession::factory()->create(['members'=>[$apprentice->id],
            'session_date'=>today()->subDays(3),'tutor_group_id'=>$previousGroup->id]);
        $vocational = 'voc-autocare-b1-t3';
        $marks = [$apprentice->id=>['code'=>'O','arrival'=>'09:00','departure'=>'16:00']];
        $this->actingAs($admin)->get(route('attendance.show',$session))->assertOk()->assertSee('Topics from approved plans');
        $this->post(route('attendance.save',$session), ['version'=>$session->version,'weekly_confirmed'=>1,'marks'=>$marks,
            'training'=>['subjects'=>[$vocational,'bad-reference']]])->assertSessionHasErrors('training.subjects');
        $this->post(route('attendance.save',$session), ['version'=>$session->version,'weekly_confirmed'=>1,'marks'=>$marks,
            'training'=>['subjects'=>[$vocational,$english],'topic_exceptions'=>[$apprentice->id=>[$english=>'partial']]]])
            ->assertSessionHasNoErrors();

        $apprentice->tutor_group_id = $newGroup->id;
        $apprentice->save();
        $plan = DeliveryPlan::approved($apprentice);
        $coverage = collect(RegisterTraining::coverage($apprentice, RegisterTraining::receipts($apprentice->id), $plan))->keyBy('subjectId');
        $this->assertSame('delivered', $coverage[$vocational]['state']);
        $this->assertTrue($coverage[$english]['partial']);
        $this->assertSame('overdue', $coverage[$english]['state']);

        $catchup = TrainingSession::factory()->create(['members'=>[$apprentice->id],
            'session_date'=>today()->subDay(),'tutor_group_id'=>$newGroup->id]);
        $this->post(route('attendance.save',$catchup), ['version'=>$catchup->version,'weekly_confirmed'=>1,'marks'=>$marks,
            'training'=>['subjects'=>[$english]]])->assertSessionHasNoErrors();
        $coverage = collect(RegisterTraining::coverage($apprentice, RegisterTraining::receipts($apprentice->id), $plan))->keyBy('subjectId');
        $this->assertSame('delivered', $coverage[$english]['state']);
        $this->assertCount(2, $coverage[$english]['records']);
        $this->get(route('apprentices.show',$apprentice))->assertOk()->assertSee('Required training coverage')->assertSee('Catch-up overdue');
    }
}
