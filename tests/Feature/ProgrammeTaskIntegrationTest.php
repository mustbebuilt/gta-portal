<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\LearningTask;
use App\Models\Programme;
use App\Models\User;
use App\Support\BlockRelease;
use App\Support\TaskCatalogue;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ProgrammeTaskIntegrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->save();

        return $u;
    }

    private function allocate(string $key): array
    {
        $p = TaskCatalogue::PROGRAMMES[$key];
        $programme = Programme::factory()->create(['standard_code' => $p['code'], 'version' => $p['version']]);
        $learner = $this->actor('apprentice');
        $tutor = $this->actor('tutor');
        $a = Apprentice::factory()->create(['programme_id' => $programme->id, 'tutor_id' => $tutor->id, 'user_id' => $learner->id]);
        $admin = $this->actor('admin');
        $this->actingAs($admin)->get('/apprentices/'.$a->id.'/learning')->assertOk()->assertSee('Assign '.$p['name'].' tasks');
        $this->post('/apprentices/'.$a->id.'/learning', ['confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();

        return [$a, $learner, $tutor, $admin];
    }

    private function task(Apprentice $a, string $key, int $block, int $number): LearningTask
    {
        return LearningTask::where('apprentice_id', $a->id)->where('reference', $key.'-b'.$block.'-t'.$number)->firstOrFail();
    }

    private function answers(LearningTask $t): array
    {
        $f = collect($t->template['fields'])->first(fn ($f) => $f['role'] === 'apprentice' && $f['key'] === 'apprentice_name');

        return [$f['name'] => 'Fictional Apprentice'];
    }

    #[TestWith(['heavy-vehicle'])] #[TestWith(['paint'])] #[TestWith(['panel'])]
    public function test_programme_allocation_is_source_specific_duplicate_safe_and_does_not_set_dates(string $key): void
    {
        [$a] = $this->allocate($key);
        $tasks = LearningTask::where('apprentice_id', $a->id)->get();
        $this->assertCount(50, $tasks);
        $this->assertTrue($tasks->every(fn ($t) => $t->template['catalogueId'] === $key && str_starts_with($t->reference, $key.'-')));
        $this->assertSame(0, $tasks->whereNotNull('due_date')->count());
        foreach (range(1, 10) as $block) {
            $this->assertCount(5, $tasks->where('block', $block));
        }
        $this->post('/apprentices/'.$a->id.'/learning', ['confirmed' => 1])->assertSessionHasErrors('confirmed');
        $this->assertDatabaseCount('learning_tasks', 50);
        $this->assertDatabaseCount('training_plan_workspaces', 0);
    }

    #[TestWith(['heavy-vehicle'])] #[TestWith(['paint'])] #[TestWith(['panel'])]
    public function test_all_source_pdfs_are_unchanged_and_every_new_task_opens_without_autocare_guidance(string $key): void
    {
        [$a, $learner] = $this->allocate($key);
        $this->actingAs($learner);
        foreach (LearningTask::where('apprentice_id', $a->id)->get() as $task) {
            $this->get('/tasks/'.$task->id)->assertOk()->assertDontSee('Approved Block 9 planning guidance')->assertDontSee('Answer both released ADAS');
            if (isset($task->template['sourcePdfFilename'])) {
                $this->assertSame($task->template['sourceTemplateHash'], hash_file('sha256', resource_path('task-pdfs/'.$task->template['sourcePdfFilename'])));
                $this->get('/tasks/'.$task->id.'/original-pdf')->assertOk()->assertDownload();
            }
        }
        $this->get('/apprentices/'.$a->id.'/curriculum-reference')->assertOk()->assertDontSee('663 programme reference hours')->assertSee('controlled programme-specific training plan');
    }

    #[TestWith(['heavy-vehicle'])] #[TestWith(['paint'])] #[TestWith(['panel'])]
    public function test_multiline_draft_submit_refer_resubmit_and_whole_task_approval_retain_exact_history(string $key): void
    {
        [$a, $l, $tutor] = $this->allocate($key);
        $t = $this->task($a, $key, 1, 3);
        $question = collect($t->template['fields'])->first(fn ($f) => preg_match('/(?:knowledge_|_q|_kq_)1$/', $f['name']));
        $text = str_repeat("A real measurement recorded on a separate line.\n", 1200).'END OF LONG ANSWER';
        $answers = [...$this->answers($t), $question['name'] => $text, 'assessor_name' => 'Forged assessor'];
        $url = '/tasks/'.$t->id;
        $this->actingAs($l)->post($url, ['version' => 1, 'intent' => 'draft', 'answers' => $answers])->assertSessionHasNoErrors();
        $this->assertSame($text, $t->fresh()->answers[$question['name']]);
        $this->assertArrayNotHasKey('assessor_name', $t->fresh()->answers);
        $this->post($url, ['version' => 1, 'intent' => 'draft', 'answers' => []])->assertSessionHasErrors('version');
        $this->post($url, ['version' => 2, 'intent' => 'submit', 'own_work' => 1, 'answers' => $answers])->assertSessionHasNoErrors();
        $this->post($url, ['version' => 3, 'intent' => 'draft'])->assertSessionHasErrors('intent');
        $this->actingAs($tutor)->post($url.'/decision', ['version' => 3, 'decision' => 'Referred', 'decision_date' => today()->toDateString(), 'feedback' => 'Add the measured value.', 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->actingAs($l)->post($url, ['version' => 4, 'intent' => 'submit', 'own_work' => 1, 'answers' => $answers])->assertSessionHasNoErrors();
        $this->actingAs($tutor)->post($url.'/decision', ['version' => 5, 'decision' => 'Approved', 'decision_date' => today()->toDateString(), 'confirmed' => 1])->assertSessionHasNoErrors();
        $saved = $t->fresh();
        $this->assertSame('Approved', $saved->status);
        $this->assertCount(2, $saved->submissions);
        $this->assertSame('Referred', $saved->submissions[0]['decision']['status']);
        $this->assertSame($text, $saved->submissions[1]['answers'][$question['name']]);
        $this->assertSame($saved->template['sha256'], $saved->submissions[1]['template_hash']);
        $pdf = $this->get($url.'/recorded-work.pdf')->assertOk();
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        if ($directory = getenv('GTA_QA_EXPORT_DIR')) {
            file_put_contents($directory.'/'.$key.'-long-answer.pdf', $pdf->getContent());
        }
    }

    #[TestWith(['heavy-vehicle'])] #[TestWith(['paint'])] #[TestWith(['panel'])]
    public function test_source_reflection_scores_remain_separate_and_tutor_fields_cannot_be_forged(string $key): void
    {
        [$a, $l] = $this->allocate($key);
        $t = $this->task($a, $key, 1, 5);
        $answers = $this->answers($t);
        $this->actingAs($l)->post('/tasks/'.$t->id, ['version' => 1, 'intent' => 'draft', 'answers' => $answers])->assertSessionHasNoErrors();
        $this->post('/tasks/'.$t->id, ['version' => 2, 'intent' => 'submit', 'own_work' => 1, 'answers' => $answers])->assertSessionHasErrors('answers');
        foreach ($t->template['fields'] as $f) {
            if ($f['type'] === 'score') {
                $this->assertNotEmpty($f['help']);
                $answers[$f['name']] = 6;
            }
            if ($f['role'] === 'trainer') {
                $answers[$f['name']] = 'Forged review';
            }
        }
        $this->post('/tasks/'.$t->id, ['version' => 2, 'intent' => 'submit', 'own_work' => 1, 'answers' => $answers])->assertSessionHasNoErrors();
        foreach ($t->template['fields'] as $f) {
            if ($f['role'] === 'trainer') {
                $this->assertArrayNotHasKey($f['name'], $t->fresh()->answers);
            }
        }
        $this->assertSame('Awaiting marking', $t->fresh()->status);
    }

    #[TestWith(['heavy-vehicle'])] #[TestWith(['paint'])] #[TestWith(['panel'])]
    public function test_next_block_needs_five_matching_source_approvals_not_foreign_references(string $key): void
    {
        [$a] = $this->allocate($key);
        $later = $this->task($a, $key, 2, 3);
        $this->assertFalse(BlockRelease::state($later)['open']);
        LearningTask::where('apprentice_id', $a->id)->where('block', 1)->update(['status' => 'Approved']);
        $this->assertTrue(BlockRelease::state($later)['open']);
        $prior = $this->task($a, $key, 1, 4);
        $schema = $prior->template;
        $schema['catalogueId'] = 'autocare';
        $prior->template = $schema;
        $prior->save();
        $this->assertFalse(BlockRelease::state($later)['open']);
    }

    #[TestWith(['heavy-vehicle'])] #[TestWith(['paint'])] #[TestWith(['panel'])]
    public function test_private_uploads_and_pdf_routes_deny_employer_other_apprentice_and_unassigned_tutor(string $key): void
    {
        Storage::fake(config('evidence.disk'));
        [$a, $l, $u] = $this->allocate($key);
        $t = $this->task($a, $key, 1, 4);
        $url = '/tasks/'.$t->id;
        $this->actingAs($l)->post($url.'/evidence', ['version' => 1, 'kind' => 'supporting-evidence', 'file' => UploadedFile::fake()->create('work.pdf', 2, 'application/pdf')])->assertSessionHasNoErrors();
        $file = $t->evidence()->firstOrFail();
        foreach (['employer', 'apprentice', 'tutor'] as $role) {
            $this->actingAs($this->actor($role))->get($url)->assertForbidden();
            $this->get($url.'/original-pdf')->assertForbidden();
            $this->get($url.'/recorded-work.pdf')->assertForbidden();
            $this->get($url.'/evidence/'.$file->id)->assertForbidden();
        }
        $this->actingAs($u)->get($url.'/evidence/'.$file->id)->assertOk();
    }

    #[TestWith(['heavy-vehicle'])] #[TestWith(['paint'])] #[TestWith(['panel'])]
    public function test_other_programme_cannot_apply_autocare_rpl_or_relabel_task_history(string $key): void
    {
        [$a, , , $admin] = $this->allocate($key);
        $this->actingAs($admin)->get('/apprentices/'.$a->id.'/training-plan')->assertOk()->assertSee('Training-plan source required')->assertDontSee('663');
        $this->post('/apprentices/'.$a->id.'/training-plan/proposal', ['version' => 0])->assertSessionHasErrors('programmeRef');
        $this->assertDatabaseCount('training_plan_workspaces', 0);
        $p = Programme::factory()->create(['standard_code' => 'ST0499', 'version' => '1.3']);
        $this->put('/apprentices/'.$a->id, ['reference' => $a->reference, 'first_name' => $a->first_name, 'last_name' => $a->last_name, 'version' => 1, 'programme_id' => $p->id])->assertSessionHasErrors('programme_id');
        $this->assertNotSame($p->id, $a->fresh()->programme_id);
        $programme = $a->programmeRecord()->firstOrFail();
        $this->put('/directories/programmes/'.$programme->id, ['name' => $programme->name, 'standard_code' => 'ST0499', 'version' => '9.9', 'level' => 3, 'active' => 1, 'record_version' => $programme->record_version])->assertSessionHasErrors('standard_code');
        $this->assertSame(TaskCatalogue::PROGRAMMES[$key]['code'], $programme->fresh()->standard_code);
    }

    #[TestWith(['heavy-vehicle'])] #[TestWith(['paint'])] #[TestWith(['panel'])]
    public function test_paper_results_and_shared_jobcard_keep_their_separate_confirmation_rules(string $key): void
    {
        [$a, , $tutor] = $this->allocate($key);
        $paper = $this->task($a, $key, 1, 1);
        $this->actingAs($tutor)->post('/tasks/'.$paper->id.'/decision', ['version' => 1, 'decision' => 'Approved', 'decision_date' => today()->toDateString(), 'test_title' => 'Actual paper title', 'marks_available' => 30, 'marks_achieved' => 28, 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertSame('Approved', $paper->fresh()->status);
        $job = $this->task($a, $key, 1, 2);
        $job->status = 'Awaiting marking';
        $job->submissions = [['version' => 1, 'answers' => [], 'decision' => null]];
        $job->save();
        $this->post('/tasks/'.$job->id.'/decision', ['version' => 1, 'decision' => 'Approved', 'decision_date' => today()->toDateString(), 'confirmed' => 1])->assertSessionHasErrors('answers.mentor_confirmation');
    }

    public function test_heavy_vehicle_checklist_keeps_item_labels_and_rejects_conflicting_inspection_states(): void
    {
        [$a, $learner] = $this->allocate('heavy-vehicle');
        DB::table('learning_block_controls')->where('apprentice_id', $a->id)->update(['enabled' => false]);
        $task = $this->task($a, 'heavy-vehicle', 10, 3);
        $this->assertSame('apprentice', collect($task->template['fields'])->firstWhere('name', 'b10t3_a_A1_done')['role']);
        $this->assertStringContainsString('Registration and ministry plates', collect($task->template['fields'])->firstWhere('name', 'b10t3_a_A1_done')['label']);
        $this->actingAs($learner)->post('/tasks/'.$task->id, ['version' => 1, 'intent' => 'draft', 'answers' => ['b10t3_a_A1_done' => 'on', 'b10t3_a_A1_na' => 'on']])->assertSessionHasErrors('answers');
        $this->assertNull($task->fresh()->answers);
        $this->post('/tasks/'.$task->id, ['version' => 1, 'intent' => 'draft', 'answers' => ['b10t3_a_A1_na' => 'on', 'b10t3_limitations' => 'Not fitted on the allocated training rig.']])->assertSessionHasNoErrors();
        $this->assertSame('on', $task->fresh()->answers['b10t3_a_A1_na']);
    }

    public function test_panel_reflections_are_unavailable_and_four_approvals_do_not_complete_a_block(): void
    {
        [$a, $learner, $tutor, $admin] = $this->allocate('panel');
        $missing = $this->task($a, 'panel', 1, 5);
        $this->legacyPlaceholder($missing);
        $this->assertSame('Source required', $missing->status);
        $this->assertSame('source-pending', $missing->template['kind']);
        $this->actingAs($learner)->get('/tasks/'.$missing->id)->assertOk()->assertSee('Source required')->assertDontSee('Submit to tutor');
        $this->post('/tasks/'.$missing->id, ['version' => 1, 'intent' => 'submit', 'own_work' => 1])->assertSessionHasErrors('intent');
        $this->get('/tasks/'.$missing->id.'/recorded-work.pdf')->assertNotFound();
        $this->actingAs($tutor)->post('/tasks/'.$missing->id.'/decision', ['version' => 1, 'decision' => 'Approved', 'decision_date' => today()->toDateString(), 'confirmed' => 1])->assertSessionHasErrors('decision');
        LearningTask::where('apprentice_id', $a->id)->where('block', 1)->where('status', 'Draft')->update(['status' => 'Approved']);
        $later = $this->task($a, 'panel', 2, 3);
        $this->assertFalse(BlockRelease::state($later)['open']);
        $this->actingAs($admin)->post('/apprentices/'.$a->id.'/block-controls', ['version' => 1, 'enabled' => 1, 'operation' => 'release', 'block' => 2, 'reason' => 'Allow practical work while the controlled reflection source is obtained.'])->assertSessionHasNoErrors();
        $this->assertTrue(BlockRelease::state($later)['open']);
        $this->assertSame('Source required', $missing->fresh()->status);
    }

    private function legacyPlaceholder(LearningTask $task): void
    {
        $block = $task->block;
        $template = ['filename' => "panel-block-{$block}-reflection-source-required", 'kind' => 'source-pending', 'catalogueId' => 'panel', 'sourceBlock' => $block, 'sourceTask' => 5, 'instructions' => 'Source required', 'version' => 'pending', 'fields' => [], 'pdfAvailable' => false];
        $template['sha256'] = json_decode(file_get_contents(resource_path('data/panel-placeholder-hashes.json')), true)[$task->reference];
        $task->template = $template;
        $task->status = 'Source required';
        $task->save();
    }

    public function test_panel_sources_upgrade_only_untouched_placeholders_once_and_preserve_dates_and_work(): void
    {
        [$a, $l, $tutor, $admin] = $this->allocate('panel');
        $rows = LearningTask::where('apprentice_id', $a->id)->where('reference', 'like', '%-t5')->orderBy('block')->get();
        foreach ($rows as $row) { $this->legacyPlaceholder($row); }
        $rows[0]->due_date = '2027-01-04'; $rows[0]->save();
        $rows[1]->answers = ['historic' => 'Keep exact answer']; $rows[1]->save();
        $rows[2]->submissions = [['historic' => 'Keep exact submission']]; $rows[2]->save();
        $rows[3]->version = 2; $rows[3]->save();
        $schema = $rows[4]->template; $schema['sha256'] = str_repeat('0', 64); $rows[4]->template = $schema; $rows[4]->save();
        $rows[5]->status = 'Approved'; $rows[5]->save();
        $rows[6]->submitted_at = now(); $rows[6]->save();
        DB::table('learning_events')->insert(['user_id' => $admin->id, 'apprentice_id' => $a->id, 'kind' => 'task', 'record_id' => $rows[7]->id, 'event' => 'historic', 'created_at' => now()]);
        $rows[8]->evidence()->create(['uploaded_by' => $l->id, 'path' => 'fixture', 'name' => 'Keep.pdf', 'mime' => 'application/pdf', 'sha256' => str_repeat('1',64), 'bytes' => 1, 'kind' => 'additional']);
        $before = $rows->slice(1,8)->map(fn ($t) => $t->fresh()->getAttributes())->all();
        $url = '/apprentices/'.$a->id.'/learning/reflection-sources';
        $this->actingAs($l)->post($url, ['confirmed' => 1])->assertForbidden();
        $this->actingAs($tutor)->post($url, ['confirmed' => 1])->assertForbidden();
        $this->actingAs($admin)->post($url)->assertSessionHasErrors('confirmed');
        $this->post($url, ['confirmed' => 1])->assertSessionHasNoErrors()->assertSessionHas('status', '2 untouched reflection placeholders loaded. Other saved records were preserved.');
        $this->assertSame('impact', $rows[0]->fresh()->template['kind']);
        $this->assertSame('Draft', $rows[0]->fresh()->status);
        $this->assertSame(2, $rows[0]->fresh()->version);
        $this->assertSame('2027-01-04', $rows[0]->fresh()->due_date->toDateString());
        $this->assertSame($before, $rows->slice(1,8)->map(fn ($t) => $t->fresh()->getAttributes())->all());
        $this->assertDatabaseCount('learning_tasks', 50);
        $control = \App\Models\LearningBlockControl::where('apprentice_id',$a->id)->first();
        $this->assertCount(2, $control->history[1]['changes']);
        $this->assertSame($rows[0]->fresh()->template['sha256'], $control->history[1]['changes'][0]['new_hash']);
        $this->post($url, ['confirmed' => 1])->assertSessionHas('status', '0 untouched reflection placeholders loaded. Other saved records were preserved.');
        $this->assertSame(2, $control->fresh()->version);
    }

    public function test_panel_source_upgrade_rolls_back_on_audit_failure_and_rejects_programme_mismatch(): void
    {
        [$a, $l, $tutor, $admin] = $this->allocate('panel');
        $row = $this->task($a,'panel',1,5); $this->legacyPlaceholder($row);
        $url = '/apprentices/'.$a->id.'/learning/reflection-sources';
        $programme = $a->programme_id;
        $a->programme_id = null; $a->save();
        $this->post($url,['confirmed'=>1])->assertSessionHasErrors('confirmed');
        $a->programme_id = $programme; $a->save();
        \Tests\Support\AuditFault::install('reject_source_event','learning_events','Fictional audit failure');
        $this->post($url,['confirmed'=>1])->assertStatus(500);
        $this->assertSame('source-pending',$row->fresh()->template['kind']);
        $this->assertSame(1,$row->fresh()->version);
    }

    public function test_panel_unassessed_scores_require_explanation_and_review_boxes_belong_to_tutor(): void
    {
        [$a,$l,$tutor] = $this->allocate('panel');
        $row = $this->task($a,'panel',1,5);
        $answers = [...$this->answers($row), 'confirm_1'=>'on'];
        $url = '/tasks/'.$row->id;
        $this->actingAs($l)->post($url,['version'=>1,'intent'=>'submit','own_work'=>1,'answers'=>$answers])->assertSessionHasErrors('answers');
        $answers['needs_practice'] = 'END skill 2 is unassessed: no suitable repair available. Other areas need assessment at the next session.';
        $answers['start_score_1'] = 11;
        $this->post($url,['version'=>1,'intent'=>'submit','own_work'=>1,'answers'=>$answers])->assertSessionHasErrors();
        $answers['start_score_1'] = 3;
        $this->post($url,['version'=>1,'intent'=>'submit','own_work'=>1,'answers'=>$answers])->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('confirm_1',$row->fresh()->answers);
        $this->assertSame('Awaiting marking',$row->fresh()->status);
        foreach(range(1,4) as $n) {
            $this->assertSame('trainer', collect($row->template['fields'])->firstWhere('name','confirm_'.$n)['role']);
        }
        $this->actingAs($tutor)->post($url.'/decision',['version'=>2,'decision'=>'Approved','decision_date'=>today()->toDateString(),'confirmed'=>1,'answers'=>['confirm_1'=>'on']])->assertSessionHasNoErrors();
        $this->assertSame('Approved',$row->fresh()->status);
        $pdf = $this->get($url.'/recorded-work.pdf')->assertOk()->assertDownload();
        if ($directory = getenv('GTA_QA_EXPORT_DIR')) {
            file_put_contents($directory.'/panel-reflection.pdf', $pdf->getContent());
        }
    }

    public function test_wrong_version_inactive_programme_and_tutor_allocation_are_rejected(): void
    {
        $admin = $this->actor('admin');
        foreach ([['1.4', true], ['vv1.5', true], ['1.5', false]] as [$version, $active]) {
            $p = Programme::factory()->create(['standard_code' => 'ST0068', 'version' => $version, 'active' => $active]);
            $a = Apprentice::factory()->create(['programme_id' => $p->id]);
            $this->actingAs($admin)->post('/apprentices/'.$a->id.'/learning', ['confirmed' => 1])->assertSessionHasErrors('confirmed');
        }
        $this->actingAs($this->actor('tutor'))->post('/apprentices/'.$a->id.'/learning', ['confirmed' => 1])->assertForbidden();
        $this->assertDatabaseCount('learning_tasks', 0);
    }

    public function test_failed_allocation_audit_rolls_back_all_tasks_and_controls(): void
    {
        $p = Programme::factory()->create(['standard_code' => 'ST0448', 'version' => '1.2']);
        $a = Apprentice::factory()->create(['programme_id' => $p->id]);
        \Tests\Support\AuditFault::install('reject_task_event','learning_events','Fictional audit failure');
        $this->actingAs($this->actor('admin'))->post('/apprentices/'.$a->id.'/learning', ['confirmed' => 1])->assertStatus(500);
        $this->assertDatabaseCount('learning_tasks', 0);
        $this->assertDatabaseCount('learning_block_controls', 0);
    }
}
