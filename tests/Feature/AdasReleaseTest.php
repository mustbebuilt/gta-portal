<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\LearningTask;
use App\Models\User;
use App\Support\TaskCatalogue;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AdasReleaseTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(string $reference = 'b5-t4'): array
    {
        $user = User::factory()->create();
        $user->role = 'apprentice';
        $user->save();
        $a = Apprentice::factory()->create(['user_id' => $user->id]);
        $template = TaskCatalogue::template($reference);
        $task = LearningTask::factory()->create(['apprentice_id' => $a->id, 'reference' => $reference, 'template' => $template]);
        $name = collect($template['fields'])->first(fn ($f) => $f['role'] === 'apprentice' && in_array($f['key'], ['apprentice_name', 'sign_apprentice_name', 'apprentice_signoff']));

        return [$task, $user, [$name['name'] => 'Fictional Apprentice']];
    }

    public function test_released_questions_are_exact_source_and_new_tasks_do_not_add_extra_achievements(): void
    {
        $ref = json_decode(file_get_contents(resource_path('data/training-plan-reference.json')), true);
        foreach ($ref['curriculumAmendment']['adasRelease']['targets'] as $target) {
            $t = TaskCatalogue::template($target['taskId']);
            $this->assertSame('gta-adas-blocks5-8-release-r2-2026-11-06', $t['adasReleaseId']);
            foreach ($target['prompts'] as $p) {
                $this->assertSame($p['question'], collect($t['fields'])->firstWhere('adasCode', $p['code'])['help']);
            }
        }
        $this->assertCount(50, TaskCatalogue::tasks());
    }

    public function test_partial_adas_draft_saves_but_submission_needs_both_answers_and_freezes_release(): void
    {
        [$t, $u, $answers] = $this->fixture();
        $url = '/tasks/'.$t->id;
        $names = $t->template['adasFieldNames'];
        $answers[$names[0]] = 'Fictional manufacturer information and referral decision.';
        $this->actingAs($u)->post($url, ['version' => 1, 'intent' => 'draft', 'answers' => $answers])->assertRedirect()->assertSessionHasNoErrors();
        $this->post($url, ['version' => 2, 'intent' => 'submit', 'own_work' => 1, 'answers' => $answers])->assertSessionHasErrors('answers.'.$names[1]);
        $this->assertSame(2, $t->fresh()->version);
        $answers[$names[1]] = 'Fictional warning-only feature; source and driver responsibilities explained.';
        $this->post($url, ['version' => 2, 'intent' => 'submit', 'own_work' => 1, 'answers' => $answers])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($t->template['sha256'], $t->fresh()->submissions[0]['template_hash']);
        $this->assertSame($answers[$names[1]], $t->fresh()->submissions[0]['answers'][$names[1]]);
    }

    public function test_original_pdf_remains_available_and_hash_checked_for_released_schema(): void
    {
        [$t, $u] = $this->fixture();
        $this->actingAs($u)->get('/tasks/'.$t->id)->assertOk()->assertSee('Download original PDF')->assertSee('does not include these portal questions');
        $this->get('/tasks/'.$t->id.'/original-pdf')->assertOk();
        $template = $t->template;
        $template['sourceTemplateHash'] = str_repeat('0', 64);
        $t->template = $template;
        $t->save();
        $this->get('/tasks/'.$t->id.'/original-pdf')->assertStatus(409);
    }

    public function test_pre_release_saved_task_keeps_its_original_schema_and_submission_rules(): void
    {
        [$t, $u, $answers] = $this->fixture();
        $c = json_decode(file_get_contents(resource_path('data/autocare-catalogue.json')), true);
        $original = $c['templates'][$t->template['sourcePdfFilename']];
        $t->template = $original;
        $t->save();
        $this->actingAs($u)->post('/tasks/'.$t->id, ['version' => 1, 'intent' => 'submit', 'own_work' => 1, 'answers' => $answers])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($original['sha256'], $t->fresh()->template['sha256']);
        $this->assertArrayNotHasKey('adasReleaseId', $t->fresh()->template);
        $this->assertSame($original['sha256'], $t->fresh()->submissions[0]['template_hash']);
    }
}
