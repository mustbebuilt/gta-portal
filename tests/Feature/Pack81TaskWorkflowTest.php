<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\LearningTask;
use App\Models\User;
use App\Support\TaskCatalogue;
use App\Support\TaskPresentation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Pack81TaskWorkflowTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(): array
    {
        $tutor = User::factory()->create(['role'=>'tutor']);
        $learner = User::factory()->create(['role'=>'apprentice']);
        $person = Apprentice::factory()->create(['user_id'=>$learner->id,'tutor_id'=>$tutor->id]);
        return [$person, $learner, $tutor];
    }

    private function task(Apprentice $person, string $reference): LearningTask
    {
        return LearningTask::factory()->create([
            'apprentice_id'=>$person->id, 'reference'=>$reference, 'template'=>TaskCatalogue::template($reference),
        ]);
    }

    private function apprenticeName(LearningTask $task): array
    {
        $field = collect($task->template['fields'])->first(fn ($field) => $field['role'] === 'apprentice'
            && in_array($field['key'], ['apprentice_name','sign_apprentice_name','apprentice_signoff'], true));
        return [$field['name']=>'Fictional Apprentice'];
    }

    private function evidenceBatch(): array
    {
        return [
            UploadedFile::fake()->createWithContent('first.pdf', "%PDF-1.4\nFirst fictional file"),
            UploadedFile::fake()->createWithContent('second.pdf', "%PDF-1.4\nSecond fictional file"),
        ];
    }

    public function test_all_200_controlled_tasks_match_and_legacy_hashes_use_the_original_form(): void
    {
        foreach (TaskCatalogue::PROGRAMMES as $key => $programme) {
            foreach (TaskCatalogue::tasks($key) as $row) {
                $template = TaskCatalogue::template($row['id'], $key);
                $task = new LearningTask(['reference'=>$row['id'],'template'=>$template]);
                $presentation = TaskPresentation::forTask($task);
                $this->assertNotNull($presentation, $row['id']);
                $this->assertSame($template['kind'], $presentation['kind']);
                $this->assertSame($template['sourceTemplateHash'], $presentation['sourcePdfSha256']);
                if ($presentation['useExistingStructuredChecks']) {
                    $this->assertSame([], TaskPresentation::checklist($presentation));
                }
                $task->template = [...$template,'sha256'=>str_repeat('0',64)];
                $this->assertNull(TaskPresentation::forTask($task));
            }
        }
    }

    public function test_combined_upload_referral_and_resubmission_pin_checks_and_evidence_per_attempt(): void
    {
        Storage::fake('local');
        [$person, $learner, $tutor] = $this->fixture();
        $task = $this->task($person, 'b1-t4');
        $route = route('tasks.show', $task);
        $this->actingAs($learner)->get($route)->assertOk()->assertSee('Vehicle details')
            ->assertSee('Activities carried out')->assertSee('Linked knowledge, skills and behaviours');
        $answers = $this->apprenticeName($task);
        $draft = ['version'=>1,'intent'=>'upload','answers'=>$answers,'activities_present'=>1,
            'activities'=>['activity-01'],'kind'=>'supporting-evidence'];
        $this->post($route, $draft + ['files'=>$this->evidenceBatch()])->assertSessionHasNoErrors();
        $this->post($route, $draft + ['files'=>$this->evidenceBatch()])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('task_evidence', 2);
        $this->assertSame(2, $task->fresh()->version);
        $submission = ['version'=>2,'intent'=>'submit','own_work'=>1,'answers'=>$answers,'files'=>[]];
        $this->post($route, $submission)->assertSessionHasNoErrors();
        $first = $task->fresh()->submissions[0];
        $this->assertCount(2, $first['evidence']);
        $this->assertSame(['activity-01'], array_column($first['answers']['_activities'], 'id'));
        $this->post($route, [...$submission,'version'=>3,'intent'=>'draft','activities_present'=>1])
            ->assertSessionHasErrors('intent');

        $this->actingAs($tutor)->get($route)->assertOk()->assertSee('first.pdf')->assertSee('Save and open next');
        $decision = ['version'=>3,'decision'=>'Referred','decision_date'=>today()->toDateString(),'confirmed'=>1];
        $this->post(route('tasks.decide',$task), $decision)->assertSessionHasErrors('feedback');
        $this->get(route('tasks.show', $task))->assertOk()->assertSee('Feedback template')->assertSee('Correct a specific point');
        $this->post(route('tasks.decide', $task), $decision + ['feedback' => 'Correct [[add detail]] before resubmitting.'])
            ->assertSessionHasErrors('feedback');
        $this->assertSame('Awaiting marking', $task->fresh()->status);
        $task->update(['due_date'=>today()->addWeek()]);
        $followup = route('actions.create', ['apprentice'=>$person,'source_task_id'=>$task->id]);
        $this->post(route('tasks.decide',$task), $decision + [
            'feedback'=>'Supply the missing measured value.', 'referral_reason_code'=>'incomplete-work', 'after_save'=>'target',
        ])->assertRedirect($followup)->assertSessionHasNoErrors();
        $this->get($followup)->assertOk()->assertSee('Supply the missing measured value.')->assertSee('Correct and resubmit');
        $this->assertDatabaseCount('smart_actions', 0);
        $this->assertSame('incomplete-work', $task->fresh()->submissions[0]['decision']['referral_reason_code']);
        $this->actingAs($learner)->get($route)->assertOk()->assertSee('Supply the missing measured value.');
        $this->post($route, [...$submission,'version'=>4,'activities_present'=>1,'activities'=>['another-task-activity']])
            ->assertSessionHasErrors('activities');
        $this->post($route, [...$submission,'version'=>4,'activities_present'=>1])->assertSessionHasNoErrors();
        $attempts = $task->fresh()->submissions;
        $this->assertSame($first['answers'], $attempts[0]['answers']);
        $this->assertSame($first['evidence'], $attempts[0]['evidence']);
        $this->assertSame([], $attempts[1]['answers']['_activities']);
        $this->assertSame('Referred', $attempts[0]['decision']['status']);
        $this->actingAs($tutor)->post(route('tasks.decide',$task), [...$decision,'version'=>5,'decision'=>'Approved'])
            ->assertSessionHasNoErrors();
        $this->get(route('tasks.recorded.pdf', $task))->assertOk()->assertHeader('Content-Type','application/pdf');
    }

    public function test_failed_submit_rolls_back_every_file_in_the_batch(): void
    {
        $disk = Storage::fake('local');
        [$person,$learner] = $this->fixture();
        $task = $this->task($person, 'b1-t4');
        $this->actingAs($learner)->post(route('tasks.save',$task), [
            'version'=>1,'intent'=>'submit','answers'=>$this->apprenticeName($task),
            'kind'=>'supporting-evidence','files'=>$this->evidenceBatch(),
        ])->assertSessionHasErrors('own_work');
        $this->assertDatabaseCount('task_evidence', 0);
        $this->assertSame([], $disk->allFiles('task-evidence'));
        $this->assertSame(1, $task->fresh()->version);
    }

    public function test_save_and_open_next_rechecks_the_filtered_queue_and_skips_other_tutors(): void
    {
        [$person,$learner,$tutor] = $this->fixture();
        [$otherPerson,$otherLearner,$otherTutor] = $this->fixture();
        $current = $this->task($person, 'b1-t4');
        $next = $this->task($person, 'b2-t3');
        $foreign = $this->task($otherPerson, 'b1-t4');

        foreach ([[$foreign,$otherLearner],[$current,$learner],[$next,$learner]] as [$task,$actor]) {
            $this->actingAs($actor)->post(route('tasks.save', $task), [
                'version'=>1,'intent'=>'submit','own_work'=>1,'answers'=>$this->apprenticeName($task),
            ])->assertSessionHasNoErrors();
            $this->travel(1)->seconds();
        }

        $filter = ['view'=>'all','search'=>$person->reference,'tutor'=>''];
        $this->actingAs($tutor)->get(route('marking.index', $filter))->assertOk()
            ->assertSee('from_marking=1')->assertDontSee('data-marking-task="'.$foreign->id.'"', false);
        $decision = ['version'=>2,'decision'=>'Approved','decision_date'=>today()->toDateString(),
            'confirmed'=>1,'after_save'=>'next','queue'=>$filter];
        $this->post(route('tasks.decide',$current), $decision)
            ->assertRedirect(route('tasks.show',['task'=>$next->id,'from_marking'=>1]+$filter));
        $this->get(route('tasks.show',['task'=>$next->id,'from_marking'=>1]+$filter))->assertOk()
            ->assertSee('Back to Work to mark');
        $person->update(['tutor_id'=>$otherTutor->id]);
        $this->post(route('tasks.decide',$next), $decision)->assertForbidden();
        $this->assertSame('Awaiting marking', $foreign->fresh()->status);
        $this->assertSame('Awaiting marking', $next->fresh()->status);
    }
}
