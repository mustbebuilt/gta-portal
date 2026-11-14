<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\LearningTask;
use App\Models\StaffDevelopmentProfile as Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StaffDevelopmentWorkflowTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role = 'staff'): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->save();

        return $u;
    }

    private function data(array $changes = []): array
    {
        return [...['version' => 0, 'kind' => 'cpd', 'date' => today()->toDateString(), 'title' => 'Fictional technical CPD', 'summary' => 'Discussed safe diagnosis and next practice', 'status' => 'In progress'], ...$changes];
    }

    private function assign(User $target, User $manager): void
    {
        $ceo = $this->actor('ceo');
        $this->actingAs($ceo)->post('/staff-development/'.$target->id.'/manager', ['version' => 0, 'manager_id' => $manager->id, 'reason' => 'Fictional line-management allocation'])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_employee_creates_own_cpd_and_reopens_it_without_private_management_access(): void
    {
        $u = $this->actor('tutor');
        $this->actingAs($u)->get('/staff-development/'.$u->id)->assertOk();
        $this->post('/staff-development/'.$u->id.'/entry', $this->data())->assertRedirect()->assertSessionHasNoErrors();
        $p = Profile::where('user_id', $u->id)->firstOrFail();
        $this->assertCount(1, $p->payload['entries']);
        $this->get('/staff-development/'.$u->id)->assertOk()->assertSee('Fictional technical CPD');
        $this->get('/staff-development/'.$u->id.'?view=manager')->assertForbidden();
    }

    public function test_admin_does_not_automatically_receive_private_hr_records_but_ceo_does(): void
    {
        $target = $this->actor('tutor');
        $this->actingAs($this->actor('admin'))->get('/staff-development/'.$target->id)->assertForbidden();
        $this->actingAs($this->actor('ceo'))->get('/staff-development/'.$target->id.'?view=manager')->assertOk();
    }

    public function test_only_ceo_assigns_manager_and_circular_assignments_are_rejected(): void
    {
        $a = $this->actor('manager');
        $b = $this->actor('tutor');
        $this->actingAs($this->actor('admin'))->post('/staff-development/'.$b->id.'/manager', ['version' => 0, 'manager_id' => $a->id, 'reason' => 'Attempt'])->assertForbidden();
        $this->assign($b, $a);
        $ceo = $this->actor('ceo');
        $this->actingAs($ceo)->post('/staff-development/'.$a->id.'/manager', ['version' => 0, 'manager_id' => $b->id, 'reason' => 'Cycle'])->assertSessionHasErrors('manager_id');
    }

    public function test_manager_private_notes_and_unshared_observations_are_excluded_from_employee_view(): void
    {
        $target = $this->actor('tutor');
        $manager = $this->actor('manager');
        $this->assign($target, $manager);
        $this->actingAs($manager)->post('/staff-development/'.$target->id.'/entry', $this->data(['version' => 1, 'kind' => 'observation', 'title' => 'PRIVATE OBSERVATION TITLE', 'private_notes' => 'SECRET MANAGEMENT TEXT']))->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($target)->get('/staff-development/'.$target->id)->assertOk()->assertDontSee('PRIVATE OBSERVATION TITLE')->assertDontSee('SECRET MANAGEMENT TEXT');
        $this->actingAs($manager)->get('/staff-development/'.$target->id.'?view=manager')->assertOk()->assertSee('PRIVATE OBSERVATION TITLE')->assertSee('SECRET MANAGEMENT TEXT');
    }

    public function test_shared_tutor_one_to_one_uses_source_headings_but_excludes_private_notes(): void
    {
        $target = $this->actor('tutor');
        $m = $this->actor('manager');
        $this->assign($target, $m);
        $this->actingAs($m)->post('/staff-development/'.$target->id.'/entry', $this->data(['version' => 1, 'kind' => 'one-to-one', 'shared' => 1, 'tutor121' => ['setbacks' => 'Fictional timetable issue', 'learningApplied' => 'Used clearer demonstrations'], 'private_notes' => 'PRIVATE STAFF NOTE']))->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($target)->get('/staff-development/'.$target->id)->assertOk()->assertSee('Fictional timetable issue')->assertDontSee('PRIVATE STAFF NOTE');
    }

    public function test_employee_cannot_create_observations_or_private_notes(): void
    {
        $u = $this->actor();
        $this->actingAs($u)->post('/staff-development/'.$u->id.'/entry', $this->data(['kind' => 'observation']))->assertForbidden();
        $this->post('/staff-development/'.$u->id.'/entry', $this->data(['private_notes' => 'Forbidden']))->assertForbidden();
        $this->assertDatabaseCount('staff_development_profiles', 0);
    }

    public function test_cpd_completion_requires_evidence_and_stale_edits_do_not_replace_current(): void
    {
        $u = $this->actor();
        $this->actingAs($u)->post('/staff-development/'.$u->id.'/entry', $this->data(['status' => 'Completed']))->assertSessionHasErrors('evidence');
        $this->post('/staff-development/'.$u->id.'/entry', $this->data(['status' => 'Completed', 'evidence' => 'Fictional certificate REF-001']))->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/staff-development/'.$u->id.'/entry', $this->data())->assertSessionHasErrors('version');
        $this->assertSame('Completed', Profile::where('user_id', $u->id)->firstOrFail()->payload['entries'][0]['status']);
    }

    public function test_staff_evidence_is_private_and_former_manager_loses_access(): void
    {
        Storage::fake(config('evidence.disk'));
        $target = $this->actor('tutor');
        $m = $this->actor('manager');
        $this->assign($target, $m);
        $this->actingAs($m)->post('/staff-development/'.$target->id.'/entry', $this->data(['version' => 1, 'kind' => 'observation', 'shared' => 1, 'private_notes' => 'Private']));
        $p = Profile::where('user_id', $target->id)->firstOrFail();
        $id = $p->payload['entries'][0]['id'];
        $this->post('/staff-development/'.$target->id.'/upload', ['version' => 2, 'entry_id' => $id, 'file' => UploadedFile::fake()->create('observation.pdf', 1, 'application/pdf')])->assertRedirect()->assertSessionHasNoErrors();
        $file = $p->fresh()->payload['entries'][0]['files'][0];
        $url = '/staff-development/'.$target->id.'/files/'.$id.'/'.$file['id'];
        $this->actingAs($target)->get($url)->assertOk();
        $ceo = $this->actor('ceo');
        $this->actingAs($ceo)->post('/staff-development/'.$target->id.'/manager', ['version' => 3, 'manager_id' => null, 'reason' => 'Manager changed'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($m)->get($url)->assertForbidden();
    }

    public function test_development_action_needs_smart_checks_and_manager_confirms_completion(): void
    {
        $u = $this->actor();
        $m = $this->actor('manager');
        $this->assign($u, $m);
        $this->actingAs($m)->post('/staff-development/'.$u->id.'/action', ['version' => 1, 'title' => 'Review five records', 'measure' => 'Five corrected records checked', 'reason' => 'Improve record quality', 'support' => 'Manager coaching', 'due' => today()->addDays(14)->toDateString(), 'achievable' => 1, 'agreed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $p = Profile::where('user_id', $u->id)->firstOrFail();
        $id = $p->payload['actions'][0]['id'];
        $this->actingAs($u)->post('/staff-development/'.$u->id.'/action-progress', ['version' => 2, 'action_id' => $id, 'status' => 'Completed', 'note' => 'Self approval'])->assertForbidden();
        $this->post('/staff-development/'.$u->id.'/action-progress', ['version' => 2, 'action_id' => $id, 'status' => 'Ready for manager check', 'note' => 'Five records ready'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($m)->post('/staff-development/'.$u->id.'/action-progress', ['version' => 3, 'action_id' => $id, 'status' => 'Completed', 'note' => 'Checked all five records'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Completed', $p->fresh()->payload['actions'][0]['status']);
    }

    public function test_brief_teaching_note_does_not_complete_full_observation_booking(): void
    {
        $u = $this->actor('tutor');
        $m = $this->actor('manager');
        $this->assign($u, $m);
        $this->actingAs($m)->post('/staff-development/'.$u->id.'/schedule', ['version' => 1, 'kind' => 'observation', 'due' => today()->toDateString()])->assertRedirect()->assertSessionHasNoErrors();
        $p = Profile::where('user_id', $u->id)->firstOrFail();
        $id = $p->payload['schedules'][0]['id'];
        $this->post('/staff-development/'.$u->id.'/entry', $this->data(['version' => 2, 'kind' => 'observation-note', 'schedule_id' => $id]))->assertSessionHasErrors('schedule_id');
        $this->assertSame('Planned', $p->fresh()->payload['schedules'][0]['status']);
        $this->post('/staff-development/'.$u->id.'/entry', $this->data(['version' => 2, 'kind' => 'observation', 'schedule_id' => $id]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Completed', $p->fresh()->payload['schedules'][0]['status']);
    }

    public function test_month_capture_uses_current_source_position_and_leaves_plan_otj_unknown(): void
    {
        $u = $this->actor('tutor');
        $m = $this->actor('manager');
        $this->assign($u, $m);
        $a = Apprentice::factory()->create(['tutor_id' => $u->id]);
        LearningTask::factory()->create(['apprentice_id' => $a->id]);
        $this->actingAs($m)->post('/staff-development/'.$u->id.'/month', ['version' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $p = Profile::where('user_id', $u->id)->firstOrFail();
        $month = $p->payload['months'][0];
        $this->assertSame(1, $month['summary']['caseload']);
        $this->assertNull($month['summary']['full_plan_on_target']);
        $this->assertNull($month['summary']['full_otj_on_target']);
        $this->assertSame(today()->format('Y-m'), $month['month']);
    }

    public function test_apprentice_and_employer_cannot_access_staff_records(): void
    {
        $u = $this->actor('tutor');
        foreach (['apprentice', 'employer'] as $role) {
            $this->actingAs($this->actor($role))->get('/staff-development')->assertForbidden();
            $this->get('/staff-development/'.$u->id)->assertForbidden();
        }
    }
}
