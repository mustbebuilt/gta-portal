<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\LearningBlockControl;
use App\Models\LearningTask;
use App\Models\Programme;
use App\Models\User;
use App\Support\TaskCatalogue;
use App\Support\TaskPresentation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class TaskPresentation75Test extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_all_200_manifest_mappings_and_14_source_variants_validate(): void
    {
        $manifest = TaskPresentation::manifest();
        $totals = ['knowledge' => 0, 'jobcard' => 0, 'practical' => 0, 'impact' => 0];
        $variants = 0;

        foreach ($manifest['programmes'] as $programme => $data) {
            $this->assertCount(50, $data['tasks'], $programme.' count');
            foreach ($data['tasks'] as $reference => $entry) {
                $totals[$entry['kind']]++;
                if (count($entry['templateHashes']) > 1) {
                    $variants++;
                }
                foreach ($entry['templateHashes'] as $hash) {
                    $template = ['filename' => $entry['filename'], 'kind' => $entry['kind'], 'sha256' => $hash];
                    $this->assertSame($entry, TaskPresentation::forSavedTask($manifest, $programme, $reference, $template));
                    $this->assertNull(TaskPresentation::forSavedTask($manifest, 'unknown', $reference, $template));
                    $this->assertNull(TaskPresentation::forSavedTask($manifest, $programme, $reference, ['sha256' => str_repeat('0', 64)] + $template));
                }
                $ids = array_column($entry['checklist'], 'id');
                $this->assertSame($ids, TaskPresentation::checkedSteps($entry, array_reverse([...$ids, ...$ids])));
                $this->assertSame([], TaskPresentation::checkedSteps($entry, []));
            }
        }

        $this->assertSame(['knowledge' => 40, 'jobcard' => 40, 'practical' => 80, 'impact' => 40], $totals);
        $this->assertSame(14, $variants);
    }

    public function test_checklist_saves_and_tutor_can_approve_and_open_next_task(): void
    {
        $tutor = User::factory()->create(['role' => 'tutor', 'active' => true, 'email_verified_at' => now()]);
        $learnerUser = User::factory()->create(['role' => 'apprentice', 'active' => true, 'email_verified_at' => now()]);
        $programme = Programme::create(['standard_code' => 'ST0499', 'version' => '1.3', 'name' => 'Autocare', 'level' => 2, 'active' => true]);
        $apprentice = Apprentice::factory()->create(['tutor_id' => $tutor->id, 'programme_id' => $programme->id, 'user_id' => $learnerUser->id, 'email' => $learnerUser->email]);

        LearningBlockControl::create(['apprentice_id' => $apprentice->id, 'unlocked_blocks' => [1], 'exceptions' => [], 'history' => [], 'version' => 1]);

        $catalogue = TaskCatalogue::tasks('autocare');
        $b1t3Meta = collect($catalogue)->firstWhere('id', 'b1-t3');
        $b1t4Meta = collect($catalogue)->firstWhere('id', 'b1-t4');
        $b1t3 = TaskCatalogue::template('b1-t3', 'autocare');
        $b1t4 = TaskCatalogue::template('b1-t4', 'autocare');

        $task1 = LearningTask::create([
            'apprentice_id' => $apprentice->id,
            'block' => 1,
            'reference' => 'b1-t3',
            'title' => $b1t3Meta['title'],
            'theme' => $b1t3Meta['theme'],
            'template' => ['catalogueId' => 'autocare', ...$b1t3],
            'status' => 'In progress',
            'version' => 1,
        ]);
        $task2 = LearningTask::create([
            'apprentice_id' => $apprentice->id,
            'block' => 1,
            'reference' => 'b1-t4',
            'title' => $b1t4Meta['title'],
            'theme' => $b1t4Meta['theme'],
            'template' => ['catalogueId' => 'autocare', ...$b1t4],
        ]);
        $task2->status = 'Awaiting marking';
        $task2->submitted_at = now()->subDay();
        $task2->submissions = [['version' => 1, 'submitted_at' => now()->subDay()->toIso8601String(), 'answers' => [], 'template_hash' => $b1t4['sha256'], 'evidence' => [], 'decision' => null]];
        $task2->save();

        // Apprentice views b1-t3 with Pack 75 checklist and saves checked activity
        $this->actingAs($learnerUser)->withSession(['auth_version' => $learnerUser->auth_version]);
        $this->get(route('tasks.show', $task1))
            ->assertOk()
            ->assertSee('Activities carried out')
            ->assertSee('Linked knowledge, skills and behaviours (KSBs)');

        $this->post(route('tasks.save', $task1), [
            'version' => 1,
            'intent' => 'draft',
            'activities_present' => '1',
            'activities' => ['activity-01'],
            'answers' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(['activity-01'], array_column($task1->fresh()->answers['_activities'], 'id'));

        // Reject invalid checklist ID
        $this->post(route('tasks.save', $task1->fresh()), [
            'version' => $task1->fresh()->version,
            'intent' => 'draft',
            'activities_present' => '1',
            'activities' => ['not-a-real-step'],
            'answers' => [],
        ])->assertSessionHasErrors('activities');

        // Move task1 to Awaiting marking and test tutor Save and open next -> redirects to task2
        $task1 = $task1->fresh();
        $task1->status = 'Awaiting marking';
        $task1->submitted_at = now()->subHours(2);
        $task1->submissions = [['version' => 1, 'submitted_at' => now()->subHours(2)->toIso8601String(), 'answers' => $task1->answers, 'template_hash' => $b1t3['sha256'], 'evidence' => [], 'decision' => null]];
        $task1->save();

        $this->actingAs($tutor)->withSession(['auth_version' => $tutor->auth_version]);
        $this->get(route('tasks.show', $task1))
            ->assertOk()
            ->assertSee('Approve task')
            ->assertSee('Return for changes')
            ->assertSee('Save and open next');

        $this->post(route('tasks.decide', $task1), [
            'version' => $task1->fresh()->version,
            'decision' => 'Approved',
            'decision_date' => today()->toDateString(),
            'feedback' => 'Good practical work.',
            'confirmed' => '1',
            'after_save' => 'next',
        ])->assertRedirect(route('tasks.show', [$task2, 'from_marking' => 1, 'view' => 'all', 'search' => '', 'tutor' => '']));

        $this->assertSame('Approved', $task1->fresh()->status);
    }
}
