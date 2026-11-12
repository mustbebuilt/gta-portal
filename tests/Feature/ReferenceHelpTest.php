<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\LearningTask;
use App\Models\User;
use App\Support\TaskCatalogue;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ReferenceHelpTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->save();

        return $u;
    }

    public function test_reference_has_approved_hours_s9_note_links_and_no_individual_approval(): void
    {
        $t = $this->actor('tutor');
        $a = Apprentice::factory()->create(['tutor_id' => $t->id]);
        $task = LearningTask::factory()->create(['apprentice_id' => $a->id]);
        $this->actingAs($t)->get('/apprentices/'.$a->id.'/curriculum-reference')->assertOk()->assertSee('663 programme reference hours')->assertSee('S9 is retained for teaching reference')->assertSee('not this apprentice')->assertSee(route('tasks.show', $task));
        $this->assertDatabaseCount('learning_tasks', 1);
    }

    public function test_reference_does_not_broaden_private_apprentice_scope(): void
    {
        $a = Apprentice::factory()->create();
        foreach (['tutor', 'apprentice', 'employer', 'careers_adviser', 'functional_skills_tutor'] as $role) {
            $this->actingAs($this->actor($role))->get('/apprentices/'.$a->id.'/curriculum-reference')->assertForbidden();
        }
    }

    public function test_own_apprentice_sees_source_and_original_block9_amendment(): void
    {
        $u = $this->actor('apprentice');
        $a = Apprentice::factory()->create(['user_id' => $u->id]);
        $task = LearningTask::factory()->create(['apprentice_id' => $a->id, 'reference' => 'b9-t3', 'template' => TaskCatalogue::template('b9-t3')]);
        $this->actingAs($u)->get('/apprentices/'.$a->id.'/curriculum-reference')->assertOk();
        $this->get('/tasks/'.$task->id)->assertOk()->assertSee('inclusive communication scenario')->assertSee('adds no tasks, hours or achievement');
        $this->assertSame(TaskCatalogue::template('b9-t3')['sha256'], $task->fresh()->template['sha256']);
    }

    public function test_help_guides_are_role_scoped_and_never_contain_record_text(): void
    {
        Apprentice::factory()->create(['first_name' => 'PrivateNeverReadOut']);
        $this->actingAs($this->actor('apprentice'))->get('/help')->assertOk()->assertSee('Submit work')->assertDontSee('Add and reopen an apprentice')->assertDontSee('Follow up a concern')->assertDontSee('PrivateNeverReadOut')->assertSee('never apprentice records')->assertSee('data-read-help', false);
        $this->actingAs($this->actor('employer'))->get('/help')->assertOk()->assertSee('Show evidenced impact')->assertDontSee('Follow up a concern')->assertDontSee('Submit work');
        $this->actingAs($this->actor('inclusion_member'))->get('/help')->assertOk()->assertSee('Follow up a concern')->assertDontSee('Add and reopen an apprentice');
    }

    public function test_inactive_and_unsigned_users_cannot_read_authenticated_help(): void
    {
        $this->get('/help')->assertRedirect('/login');
        $u = $this->actor('admin');
        $u->active = false;
        $u->save();
        $this->actingAs($u)->get('/help')->assertForbidden();
    }
}
