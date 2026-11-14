<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\LearningTask;
use App\Models\User;
use App\Support\TaskCatalogue;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class LearningWorkflowTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->save();

        return $u;
    }

    private function task(string $ref = 'b1-t4'): array
    {
        $tutor = $this->actor('tutor');
        $learner = $this->actor('apprentice');
        $a = Apprentice::factory()->create(['tutor_id' => $tutor->id, 'user_id' => $learner->id]);
        $t = LearningTask::factory()->create(['apprentice_id' => $a->id, 'reference' => $ref, 'template' => TaskCatalogue::template($ref)]);

        return [$t, $learner, $tutor];
    }

    private function answers(LearningTask $t): array
    {
        $f = collect($t->template['fields'])->first(fn ($f) => $f['role'] === 'apprentice' && in_array($f['key'], ['apprentice_name', 'sign_apprentice_name', 'apprentice_signoff']));

        return [$f['name'] => 'Fictional Apprentice'];
    }

    public function test_explicit_allocation_creates_fifty_source_tasks_once_without_dates(): void
    {
        $programme = \App\Models\Programme::factory()->create(['standard_code'=>'ST0499','version'=>'1.3']);
        $a = Apprentice::factory()->create(['programme_id'=>$programme->id]);
        $this->actingAs($this->actor('admin'));
        $this->get('/apprentices/'.$a->id.'/learning')->assertOk();
        $this->post('/apprentices/'.$a->id.'/learning', ['confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('learning_tasks', 50);
        $this->assertSame(0, LearningTask::whereNotNull('due_date')->count());
        $this->post('/apprentices/'.$a->id.'/learning', ['confirmed' => 1])->assertSessionHasErrors('confirmed');
        $this->assertDatabaseCount('learning_tasks', 50);
        $this->get('/apprentices/'.$a->id.'/learning')->assertOk()->assertSee('Task approval');
    }

    public function test_draft_submit_refer_resubmit_approve_retains_history(): void
    {
        [$t,$l,$u] = $this->task();
        $url = '/tasks/'.$t->id;
        $this->actingAs($l)->post($url, ['version' => 1, 'intent' => 'draft', 'answers' => $this->answers($t)])->assertRedirect()->assertSessionHasNoErrors();
        $this->get($url)->assertOk()->assertSee('Fictional Apprentice');
        $this->post($url, ['version' => 2, 'intent' => 'submit', 'own_work' => 1, 'answers' => $this->answers($t)])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Awaiting marking', $t->fresh()->status);
        $this->post($url, ['version' => 3, 'intent' => 'draft'])->assertSessionHasErrors('intent');
        $this->actingAs($u)->post($url.'/decision', ['version' => 3, 'decision' => 'Referred', 'decision_date' => today()->toDateString(), 'feedback' => 'Record actual readings.', 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($l)->post($url, ['version' => 4, 'intent' => 'submit', 'own_work' => 1, 'answers' => $this->answers($t)])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($u)->post($url.'/decision', ['version' => 5, 'decision' => 'Approved', 'decision_date' => today()->toDateString(), 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $saved = $t->fresh();
        $this->assertSame('Approved', $saved->status);
        $this->assertCount(2, $saved->submissions);
        $this->assertSame('Referred', $saved->submissions[0]['decision']['status']);
        $this->assertSame($u->id, $saved->submissions[1]['decision']['tutor_id']);
        $this->get($url)->assertOk()->assertSee('Record actual readings.');
    }

    #[TestWith(['employer'])] #[TestWith(['mentor'])] #[TestWith(['careers_adviser'])] #[TestWith(['functional_skills_tutor'])]
    public function test_unrelated_roles_are_denied(string $role): void
    {
        [$t] = $this->task();
        $this->actingAs($this->actor($role))->get('/tasks/'.$t->id)->assertForbidden();
        $this->post('/tasks/'.$t->id, ['version' => 1, 'intent' => 'draft'])->assertForbidden();
        $this->post('/tasks/'.$t->id.'/decision', [])->assertForbidden();
    }

    public function test_unassigned_tutor_and_other_apprentice_are_denied(): void
    {
        [$t] = $this->task();
        foreach (['tutor', 'apprentice'] as $role) {
            $this->actingAs($this->actor($role))->get('/tasks/'.$t->id)->assertForbidden();
            $this->post('/tasks/'.$t->id.'/evidence', [])->assertForbidden();
        }
    }

    public function test_answer_and_decision_permissions_are_separate(): void
    {
        [$t,$l,$u] = $this->task();
        $this->actingAs($u)->post('/tasks/'.$t->id, ['version' => 1, 'intent' => 'draft'])->assertForbidden();
        $this->actingAs($l)->post('/tasks/'.$t->id.'/decision', [])->assertForbidden();
    }

    public function test_stale_and_forged_fields_do_not_override_state(): void
    {
        [$t,$l] = $this->task();
        $this->actingAs($l)->post('/tasks/'.$t->id, ['version' => 1, 'intent' => 'draft', 'status' => 'Approved', 'apprentice_id' => 999, 'answers' => $this->answers($t)])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Draft', $t->fresh()->status);
        $this->post('/tasks/'.$t->id, ['version' => 1, 'intent' => 'draft'])->assertSessionHasErrors('version');
    }

    public function test_submission_needs_name_and_own_work(): void
    {
        [$t,$l] = $this->task();
        $this->actingAs($l)->post('/tasks/'.$t->id, ['version' => 1, 'intent' => 'submit'])->assertSessionHasErrors('own_work');
        $this->post('/tasks/'.$t->id, ['version' => 1, 'intent' => 'submit', 'own_work' => 1])->assertSessionHasErrors('answers');
    }

    public function test_checklist_upload_is_private_and_snapshot_pinned(): void
    {
        Storage::fake(config('evidence.disk'));
        [$t,$l,$u] = $this->task('b1-t3');
        $url = '/tasks/'.$t->id;
        $this->actingAs($l)->post($url, ['version' => 1, 'intent' => 'submit', 'own_work' => 1, 'answers' => $this->answers($t)])->assertSessionHasErrors('answers');
        $this->post($url.'/evidence', ['version' => 1, 'kind' => 'inspection-checklist', 'file' => UploadedFile::fake()->create('check.pdf', 2, 'application/pdf')])->assertRedirect()->assertSessionHasNoErrors();
        $f = $t->evidence()->firstOrFail();
        Storage::disk(config('evidence.disk'))->assertExists($f->path);
        $this->post($url, ['version' => 2, 'intent' => 'submit', 'own_work' => 1, 'answers' => $this->answers($t)])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($f->id, $t->fresh()->submissions[0]['evidence'][0]['id']);
        $this->actingAs($this->actor('employer'))->get($url.'/evidence/'.$f->id)->assertForbidden();
        $this->actingAs($u)->get($url.'/evidence/'.$f->id)->assertOk();
    }

    public function test_bad_upload_and_upload_to_locked_submission_are_rejected(): void
    {
        Storage::fake(config('evidence.disk'));
        [$t,$l] = $this->task();
        $this->actingAs($l)->post('/tasks/'.$t->id.'/evidence', ['version' => 1, 'kind' => 'supporting-evidence', 'file' => UploadedFile::fake()->create('bad.php', 1, 'text/plain')])->assertSessionHasErrors('file');
        $t->status = 'Awaiting marking';
        $t->save();
        $this->post('/tasks/'.$t->id.'/evidence', ['version' => 1, 'kind' => 'supporting-evidence', 'file' => UploadedFile::fake()->create('good.pdf', 1, 'application/pdf')])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('task_evidence', 0);
    }

    public function test_impact_scores_may_be_partial_in_draft_only(): void
    {
        [$t,$l] = $this->task('b1-t5');
        $a = $this->answers($t);
        $this->actingAs($l)->post('/tasks/'.$t->id, ['version' => 1, 'intent' => 'draft', 'answers' => $a])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/tasks/'.$t->id, ['version' => 2, 'intent' => 'submit', 'own_work' => 1, 'answers' => $a])->assertSessionHasErrors('answers');
        foreach ($t->template['fields'] as $f) {
            if ($f['type'] === 'score') {
                $a[$f['name']] = 7;
            }
        }$this->post('/tasks/'.$t->id, ['version' => 2, 'intent' => 'submit', 'own_work' => 1, 'answers' => $a])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_paper_test_rejects_invalid_score_and_records_explicit_outcome(): void
    {
        [$t,$l,$u] = $this->task('b1-t1');
        $data = ['version' => 1, 'decision' => 'Approved', 'decision_date' => today()->toDateString(), 'confirmed' => 1, 'test_title' => 'Block 1 Knowledge Assessment', 'marks_available' => 20, 'marks_achieved' => 21];
        $this->actingAs($u)->post('/tasks/'.$t->id.'/decision', $data)->assertSessionHasErrors('marks_achieved');
        $this->post('/tasks/'.$t->id.'/decision', [...$data, 'marks_achieved' => 18])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Approved', $t->fresh()->status);
        $this->assertEquals(18, $t->fresh()->submissions[0]['decision']['source_fields']['marks_achieved']);
        $this->get('/tasks/'.$t->id)->assertOk()->assertSee('Block 1 Knowledge Assessment');
    }

    public function test_jobcard_approval_requires_mentor_verification(): void
    {
        [$t,$l,$u] = $this->task('b1-t2');
        $t->status = 'Awaiting marking';
        $t->submissions = [['version' => 1, 'answers' => [], 'submitted_at' => '2026-11-01', 'decision' => null]];
        $t->save();
        $this->actingAs($u)->post('/tasks/'.$t->id.'/decision', ['version' => 1, 'decision' => 'Approved', 'decision_date' => today()->toDateString(), 'confirmed' => 1])->assertSessionHasErrors('answers.mentor_confirmation');
    }

    public function test_marking_deadline_is_more_than_seven_days_and_unknown_is_not_zero(): void
    {
        $this->travelTo(Carbon::parse('2026-11-11 12:00:00'));
        [$t] = $this->task();
        $t->status = 'Awaiting marking';
        $t->submitted_at = now()->subDays(7);
        $this->assertFalse($t->markingOverdue());
        $t->submitted_at = now()->subDays(7)->subSecond();
        $this->assertTrue($t->markingOverdue());
        $t->submitted_at = null;
        $this->assertFalse($t->markingOverdue());
    }

    public function test_all_41_pdfs_match_hashes_and_download_works_for_owner(): void
    {
        $hashes = json_decode(file_get_contents(resource_path('data/original-pdf-hashes.json')), true);
        $this->assertCount(41, $hashes);
        foreach ($hashes as $name => $hash) {
            $this->assertSame($hash, hash_file('sha256', resource_path('task-pdfs/'.$name)));
        }[$t,$l] = $this->task();
        $this->actingAs($l)->get('/tasks/'.$t->id.'/original-pdf')->assertOk()->assertDownload();
    }

    public function test_reopened_answers_are_html_escaped(): void
    {
        [$t,$l] = $this->task();
        $answers = $this->answers($t);
        $answers[array_key_first($answers)] = '<script>bad()</script>';
        $this->actingAs($l)->post('/tasks/'.$t->id, ['version' => 1, 'intent' => 'draft', 'answers' => $answers])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/tasks/'.$t->id)->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>bad()</script>', false);
    }

    public function test_tutor_decision_cannot_precede_the_submission(): void
    {
        [$t,$l,$u] = $this->task();
        $t->status = 'Awaiting marking';
        $t->submitted_at = now();
        $t->save();
        $this->actingAs($u)->post('/tasks/'.$t->id.'/decision', ['version' => 1, 'decision' => 'Approved', 'decision_date' => today()->subDay()->toDateString(), 'confirmed' => 1])->assertSessionHasErrors('decision_date');
        $this->assertSame('Awaiting marking', $t->fresh()->status);
    }

    public function test_original_pdf_must_match_the_recorded_source_version(): void
    {
        [$t,$l] = $this->task();
        $schema = $t->template;
        $schema[isset($schema['sourceTemplateHash']) ? 'sourceTemplateHash' : 'sha256'] = str_repeat('0', 64);
        $t->template = $schema;
        $t->save();
        $this->actingAs($l)->get('/tasks/'.$t->id.'/original-pdf')->assertStatus(409);
    }
}
