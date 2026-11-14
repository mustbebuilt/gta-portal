<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\LearningTask;
use App\Models\Programme;
use App\Models\User;
use App\Support\TaskCatalogue;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class TaskLibraryTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role = 'admin'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function apprentice(string $key): Apprentice
    {
        $source = TaskCatalogue::PROGRAMMES[$key];
        $p = Programme::factory()->create(['standard_code' => $source['code'], 'version' => $source['version']]);
        return Apprentice::factory()->create(['programme_id' => $p->id]);
    }

    #[TestWith(['autocare'])] #[TestWith(['heavy-vehicle'])] #[TestWith(['paint'])] #[TestWith(['panel'])]
    public function test_every_loaded_task_opens_as_a_blank_disabled_preview_without_creating_learner_records(string $key): void
    {
        $this->actingAs($this->actor('tutor'));
        $this->get('/task-library?programme='.$key)->assertOk()->assertSee('50 tasks in the full catalogue')
            ->assertSee('online answer fields')->assertSee('completed on paper');
        foreach (TaskCatalogue::tasks($key) as $task) {
            $this->get('/task-library/'.$key.'/'.$task['id'])->assertOk()->assertSee($task['title'])
                ->assertSee('Blank source preview only')->assertSee('fieldset disabled', false)
                ->assertDontSee('name="intent"', false)->assertDontSee('name="confirmed"', false);
        }
        $this->assertDatabaseCount('learning_tasks', 0);
        $this->assertDatabaseCount('task_evidence', 0);
        $this->assertDatabaseCount('learning_events', 0);
        $this->assertDatabaseCount('apprentices', 0);
    }

    #[TestWith(['autocare'])] #[TestWith(['heavy-vehicle'])] #[TestWith(['paint'])] #[TestWith(['panel'])]
    public function test_four_explicit_programmes_allocate_exactly_five_tasks_per_block_with_unchanged_source_pdfs(string $key): void
    {
        $a = $this->apprentice($key);
        $this->actingAs($this->actor())->post('/apprentices/'.$a->id.'/learning', ['confirmed' => 1])->assertSessionHasNoErrors();
        $tasks = LearningTask::where('apprentice_id', $a->id)->get();
        $this->assertCount(50, $tasks);
        foreach (range(1, 10) as $block) { $this->assertCount(5, $tasks->where('block', $block)); }
        $this->assertTrue($tasks->every(fn ($row) => $row->template['catalogueId'] === $key && $row->due_date === null));
        $this->post('/apprentices/'.$a->id.'/learning', ['confirmed' => 1])->assertSessionHasErrors('confirmed');
        $this->assertDatabaseCount('learning_tasks', 50);
    }

    public function test_only_active_main_teaching_and_management_roles_can_browse_blank_sources(): void
    {
        foreach (['admin', 'ceo', 'tutor'] as $role) {
            $this->actingAs($this->actor($role))->get('/task-library?block=1')->assertOk();
        }
        foreach (['apprentice', 'employer', 'inclusion_member', 'mentor', 'manager', 'functional_skills_tutor'] as $role) {
            $this->actingAs($this->actor($role))->get('/task-library')->assertForbidden();
            $this->get('/task-library/paint/paint-b1-t1')->assertForbidden();
            $this->get('/task-library/paint/paint-b1-t1/source.pdf')->assertForbidden();
        }
        $user = $this->actor();$this->actingAs($user);$user->forceFill(['active' => false])->save();
        $this->get('/task-library')->assertForbidden();
    }

    public function test_preview_does_not_read_saved_answers_or_flash_values_and_has_no_write_endpoint(): void
    {
        $a = Apprentice::factory()->create();
        LearningTask::factory()->create(['apprentice_id' => $a->id, 'reference' => 'b1-t4',
            'template' => TaskCatalogue::template('b1-t4'), 'answers' => ['private' => 'PRIVATE-SAVED-ANSWER']]);
        $before = DB::table('learning_tasks')->get()->toJson();
        $field = collect(TaskCatalogue::template('b1-t4')['fields'])->firstWhere('role', 'apprentice')['name'];
        $this->actingAs($this->actor())->withSession(['_old_input' => ['answers' => [$field => 'PRIVATE-FLASH-ANSWER']]])
            ->get('/task-library/autocare/b1-t4')->assertOk()->assertDontSee('PRIVATE-FLASH-ANSWER')->assertDontSee('PRIVATE-SAVED-ANSWER');
        $this->post('/task-library/autocare/b1-t4', ['intent' => 'submit', 'answers' => [$field => 'NEVER-SAVE']])->assertStatus(405);
        $this->assertSame($before, DB::table('learning_tasks')->get()->toJson());
        $this->assertDatabaseCount('learning_events', 0);
    }

    public function test_paper_result_preview_is_explicit_and_block_filter_shows_exactly_five_tasks(): void
    {
        $this->actingAs($this->actor())->get('/task-library/paint/paint-b1-t1')->assertOk()
            ->assertSee('Paper knowledge assessment result')->assertSee('No online questions or automatic pass threshold');
        $response = $this->get('/task-library?programme=panel&block=7')->assertOk();
        $this->assertSame(5, substr_count($response->getContent(), '>Preview task</a>'));
        $response->assertSee('panel-b7-t5')->assertDontSee('panel-b6-t5');
    }

    public function test_blank_pdf_matches_its_released_hash_and_wrong_programme_reference_is_not_accepted(): void
    {
        $this->actingAs($this->actor())->get('/task-library/paint/paint-b1-t4/source.pdf')->assertOk()->assertDownload();
        $template = TaskCatalogue::template('paint-b1-t4', 'paint');
        $this->assertSame($template['sourceTemplateHash'], hash_file('sha256', resource_path('task-pdfs/'.$template['sourcePdfFilename'])));
        $this->get('/task-library/paint/panel-b1-t4')->assertNotFound();
        $this->get('/task-library/light-vehicle/lv-b1-t4')->assertNotFound();
        $this->get('/task-library/paint/not-a-task/source.pdf')->assertNotFound();
    }

    public function test_missing_programme_cannot_default_to_autocare_for_a_new_allocation(): void
    {
        $a = Apprentice::factory()->create(['programme' => 'Light Vehicle Level 3']);
        $this->assertSame('autocare', TaskCatalogue::keyFor($a)); // Saved legacy-read compatibility remains.
        $this->assertNull(TaskCatalogue::allocationKey($a));
        $this->actingAs($this->actor())->get('/apprentices/'.$a->id.'/learning')->assertOk()
            ->assertSee('An unlinked record does not default to Autocare')->assertDontSee('Assign Autocare Level 2 tasks');
        $this->post('/apprentices/'.$a->id.'/learning', ['confirmed' => 1, 'programme' => 'autocare'])->assertSessionHasErrors('confirmed');
        $this->assertDatabaseCount('learning_tasks', 0);
        $this->assertDatabaseCount('learning_block_controls', 0);
        $this->assertDatabaseCount('learning_events', 0);
    }

    public function test_preexisting_unmapped_autocare_answers_and_history_are_preserved_and_readable(): void
    {
        $a = Apprentice::factory()->create();
        $task = LearningTask::factory()->create(['apprentice_id' => $a->id, 'reference' => 'b1-t4',
            'template' => TaskCatalogue::template('b1-t4'), 'answers' => ['private' => 'RETAINED-ANSWER'],
            'submissions' => []]);
        $before = $task->fresh()->getRawOriginal();
        $this->actingAs($this->actor())->get('/tasks/'.$task->id)->assertOk();
        $this->get('/apprentices/'.$a->id.'/learning')->assertOk();
        $this->assertSame($before, $task->fresh()->getRawOriginal());
    }

    public function test_retained_curriculum_notices_are_not_cleared_by_a_blank_preview(): void
    {
        $this->actingAs($this->actor())->get('/task-library?programme=paint')->assertSee('Controlled Paint SOW version');
        $this->get('/task-library/autocare/b1-t4')->assertSee('Existing Autocare KSB reconciliation');
        $this->assertDatabaseCount('source_reconciliations', 0);
    }

    public function test_missing_pdf_sources_block_preview_download_and_allocation_without_partial_records(): void
    {
        $a = $this->apprentice('paint');$this->actingAs($this->actor());
        $original = base_path();
        $temporary = storage_path('framework/testing/task-source-'.bin2hex(random_bytes(10)));
        mkdir($temporary.'/resources/data', 0700, true);
        foreach (glob($original.'/resources/data/*.json') as $file) { copy($file, $temporary.'/resources/data/'.basename($file)); }
        $this->app->setBasePath($temporary); // Isolated source tree, intentionally no PDF assets.
        try {
            $this->get('/task-library?programme=paint')->assertOk()->assertSee('need checking')
                ->assertDontSee('>Preview task</a>', false);
            $this->get('/task-library/paint/paint-b1-t4')->assertStatus(409);
            $this->get('/task-library/paint/paint-b1-t4/source.pdf')->assertStatus(409);
            $this->post('/apprentices/'.$a->id.'/learning', ['confirmed' => 1])->assertSessionHasErrors('confirmed');
            $this->assertDatabaseCount('learning_tasks', 0);
            $this->assertDatabaseCount('learning_block_controls', 0);
            $this->assertDatabaseCount('learning_events', 0);
        } finally {
            $this->app->setBasePath($original);
            (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($temporary);
        }
    }
}
