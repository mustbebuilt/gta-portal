<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\LearningTask;
use App\Models\ProgressReviewDraft;
use App\Models\SupportConcern;
use App\Models\SupportPlan;
use App\Models\User;
use App\Support\TaskCatalogue;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class NativePdfDownloadTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role = 'admin'): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->save();

        return $u;
    }

    public function test_recorded_task_pdf_is_private_branded_and_does_not_modify_source_or_record(): void
    {
        $learner = $this->actor('apprentice');
        $a = Apprentice::factory()->create(['user_id' => $learner->id, 'reference' => 'PDF-DEMO']);
        $task = LearningTask::factory()->create(['apprentice_id' => $a->id, 'reference' => 'b1-t4', 'title' => 'Task 4: Basic brake and tyre condition check', 'theme' => 'Motor Vehicle Foundation Skills', 'template' => TaskCatalogue::template('b1-t4')]);
        $field = collect($task->template['fields'])->firstWhere('role', 'apprentice');
        $task->answers = [$field['name'] => 'Fictional Apprentice - checked local example'];
        $task->save();
        $r = $this->actingAs($learner)->get('/tasks/'.$task->id.'/recorded-work.pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $r->getContent());
        $this->assertStringContainsString('attachment; filename="GTA-Task-PDF-DEMO', $r->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->assertSame(1, $task->fresh()->version);
        $this->assertDatabaseCount('learning_events', 0);
        if ($path = getenv('GTA_PDF_QA_OUTPUT')) {
            file_put_contents($path, $r->getContent());
        }
    }

    public function test_employer_unassigned_tutor_and_another_apprentice_cannot_download_task_pdf(): void
    {
        $a = Apprentice::factory()->create();
        $t = LearningTask::factory()->create(['apprentice_id' => $a->id]);
        foreach (['employer', 'tutor', 'apprentice'] as $role) {
            $this->actingAs($this->actor($role))->get('/tasks/'.$t->id.'/recorded-work.pdf')->assertForbidden();
        }
    }

    public function test_operational_pdf_retains_source_scope_and_unknowns_without_granting_specialist_access(): void
    {
        $u = $this->actor('tutor');
        $a = Apprentice::factory()->create(['tutor_id' => $u->id]);
        $other = Apprentice::factory()->create();
        $this->actingAs($u)->get('/reports/operational.pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get('/reports/operational.pdf?apprentice='.$other->id)->assertNotFound();
        $this->actingAs($this->actor('careers_adviser'))->get('/reports/operational.pdf')->assertForbidden();
    }

    public function test_review_pdf_keeps_draft_privacy_and_nested_record_checks(): void
    {
        $mentor = $this->actor('mentor');
        $a = Apprentice::factory()->create(['mentor_id' => $mentor->id]);
        $review = ProgressReviewDraft::factory()->create(['apprentice_id' => $a->id]);
        $this->actingAs($mentor)->get('/apprentices/'.$a->id.'/reviews/'.$review->id.'/draft.pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $other = Apprentice::factory()->create(['mentor_id' => $mentor->id]);
        $this->get('/apprentices/'.$other->id.'/reviews/'.$review->id.'/draft.pdf')->assertNotFound();
        foreach (['apprentice', 'employer'] as $role) {
            $this->actingAs($this->actor($role))->get('/apprentices/'.$a->id.'/reviews/'.$review->id.'/draft.pdf')->assertForbidden();
        }$this->assertSame(1, $review->fresh()->version);
    }

    public function test_user_entered_markup_cannot_execute_php_in_the_pdf_renderer(): void
    {
        $a = Apprentice::factory()->create();
        $t = LearningTask::factory()->create(['apprentice_id' => $a->id]);
        $proof = storage_path('framework/pdf-execution-should-not-exist');
        $t->title = '<?php file_put_contents("'.$proof.'","bad"); ?>';
        $t->save();
        $this->actingAs($this->actor())->get('/tasks/'.$t->id.'/recorded-work.pdf')->assertOk();
        $this->assertFileDoesNotExist($proof);
    }

    public function test_private_asp_pdf_requires_approved_snapshot_and_current_team_scope(): void
    {
        $a = Apprentice::factory()->create();
        $c = SupportConcern::factory()->create(['apprentice_id' => $a->id]);
        $p = SupportPlan::create(['apprentice_id' => $a->id, 'concern_ids' => [$c->id], 'draft' => ['fields' => ['support' => 'Unapproved replacement must not export']]]);
        $this->actingAs($this->actor())->get('/support-plans/'.$p->id.'/approved.pdf')->assertStatus(409);
        $p->active_plan = ['approved_at' => now()->toIso8601String(), 'fields' => ['support' => 'Fictional approved support'], 'codes' => [$c->id => 'IM36'], 'reason' => 'Fictional approval'];
        $p->save();
        $this->get('/support-plans/'.$p->id.'/approved.pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        foreach (['tutor', 'apprentice', 'employer'] as $role) {
            $this->actingAs($this->actor($role))->get('/support-plans/'.$p->id.'/approved.pdf')->assertForbidden();
        }
    }
}
