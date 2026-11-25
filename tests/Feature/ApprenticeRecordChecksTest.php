<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\Employer;
use App\Models\LearningTask;
use App\Models\Programme;
use App\Models\StaffDuty;
use App\Models\TutorGroup;
use App\Models\User;
use App\Support\ApprenticeRecordChecks as C;
use App\Support\StaffDuties;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ApprenticeRecordChecksTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function f(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tutor = User::factory()->create(['role' => 'tutor']);
        $a = Apprentice::factory()->create(['tutor_id' => $tutor->id, 'programme_id' => null, 'employer_id' => null, 'tutor_group_id' => null, 'mentor_id' => null, 'careers_adviser_id' => null, 'maths_tutor_id' => null, 'english_tutor_id' => null, 'user_id' => null]);

        return compact('admin', 'tutor', 'a');
    }

    public function test_missing_links_are_prompts_and_never_create_records(): void
    {
        $f = $this->f();
        $rows = C::rows($f['admin'], $f['a']);
        $this->assertSame('check', $rows['programme']['position']);
        $this->assertSame('check', $rows['tasks']['position']);
        $this->assertSame('check', $rows['delivery']['position']);
        $this->assertSame('recorded', $rows['tutor']['position']);
        $this->assertSame('check-if-needed', $rows['maths_tutor']['position']);
        $this->assertDatabaseCount('learning_tasks', 0);
        $this->assertDatabaseCount('individual_delivery_plans', 0);
        $this->assertDatabaseCount('account_onboarding_tokens', 0);
    }

    public function test_legacy_text_does_not_replace_checked_directory_links(): void
    {
        $f = $this->f();
        $f['a']->update(['programme' => 'Fictional legacy programme', 'employer' => 'Fictional legacy employer']);
        $rows = C::rows($f['admin'], $f['a']);
        $this->assertSame('check', $rows['programme']['position']);
        $this->assertSame('check', $rows['employer']['position']);
    }

    public function test_inactive_programme_employer_and_mismatching_group_need_checking(): void
    {
        $f = $this->f();
        $p = Programme::factory()->create(['active' => false]);
        $e = Employer::factory()->create(['active' => false]);
        $g = TutorGroup::factory()->create(['tutor_id' => $f['tutor']->id, 'programme_id' => null]);
        $f['a']->update(['programme_id' => $p->id, 'employer_id' => $e->id, 'tutor_group_id' => $g->id]);
        $rows = C::rows($f['admin'], $f['a']->fresh());
        foreach (['programme', 'employer', 'group'] as $key) {
            $this->assertSame('check', $rows[$key]['position']);
        }
    }

    public function test_group_needs_real_non_null_programme_and_tutor_matches(): void
    {
        $f = $this->f();
        $p = Programme::factory()->create();
        $g = TutorGroup::factory()->create(['tutor_id' => $f['tutor']->id, 'programme_id' => $p->id]);
        $f['a']->update(['programme_id' => $p->id, 'tutor_group_id' => $g->id]);
        $this->assertSame('recorded', C::rows($f['admin'], $f['a'])['group']['position']);
        $g->update(['tutor_id' => null, 'programme_id' => null]);
        $f['a']->update(['tutor_id' => null, 'programme_id' => null]);
        $this->assertSame('check', C::rows($f['admin'], $f['a']->fresh())['group']['position']);
    }

    public function test_wrong_role_or_revoked_duty_is_not_a_valid_staff_assignment(): void
    {
        $f = $this->f();
        $mentor = User::factory()->create(['role' => 'staff']);
        $f['a']->update(['mentor_id' => $mentor->id]);
        $this->assertSame('check', C::rows($f['admin'], $f['a'])['mentor']['position']);
        StaffDuties::save($mentor, $f['admin'], 1, 1, 0, ['mentor' => true]);
        $this->assertSame('recorded', C::rows($f['admin'], $f['a'])['mentor']['position']);
        StaffDuty::whereKey($mentor->id)->update(['mentor' => false]);
        $this->assertSame('check', C::rows($f['admin'], $f['a'])['mentor']['position']);
    }

    public function test_subject_assignment_remains_a_requirement_check_without_assuming_exemption(): void
    {
        $f = $this->f();
        $rows = C::rows($f['admin'], $f['a']);
        $this->assertSame('check-if-needed', $rows['english_tutor']['position']);
        $this->assertStringContainsString('do not infer an exemption', $rows['english_tutor']['text']);
        $this->actingAs($f['admin'])->get('/apprentices/'.$f['a']->id.'/record-checks')->assertOk()->assertSee('Check assessed requirement')->assertDontSee('100%');
    }

    public function test_reversed_or_missing_learning_dates_are_held_without_changing_them(): void
    {
        $f = $this->f();
        $f['a']->update(['start_date' => '2026-11-15', 'planned_end_date' => '2026-11-14']);
        $this->assertSame('check', C::rows($f['admin'], $f['a'])['dates']['position']);
        $this->assertSame('2026-11-14', $f['a']->fresh()->planned_end_date->toDateString());
        $f['a']->update(['planned_end_date' => '2029-11-15']);
        $this->assertSame('recorded', C::rows($f['admin'], $f['a'])['dates']['position']);
    }

    public function test_account_needs_current_active_apprentice_role_and_no_managed_block(): void
    {
        $f = $this->f();
        $u = User::factory()->create(['role' => 'employer']);
        $f['a']->forceFill(['user_id' => $u->id])->save();
        $this->assertSame('check', C::rows($f['admin'], $f['a'])['account']['position']);
        $u->forceFill(['role' => 'apprentice'])->save();
        $this->assertSame('recorded', C::rows($f['admin'], $f['a'])['account']['position']);
        $u->forceFill(['active' => false])->save();
        $this->assertSame('check', C::rows($f['admin'], $f['a'])['account']['position']);
    }

    public function test_source_pending_tasks_need_checking_and_approved_tasks_do_not_complete_programme(): void
    {
        $f = $this->f();
        $task = LearningTask::factory()->create(['apprentice_id' => $f['a']->id, 'template' => ['kind' => 'source-pending'], 'answers' => ['private' => 'PRIVATE-TASK-44']]);
        $this->assertSame('check', C::rows($f['admin'], $f['a'])['tasks']['position']);
        $task->update(['template' => ['kind' => 'task']]);
        $this->assertSame('recorded', C::rows($f['admin'], $f['a'])['tasks']['position']);
        $this->actingAs($f['admin'])->get('/apprentices/'.$f['a']->id.'/record-checks')->assertDontSee('PRIVATE-TASK-44')->assertSee('does not mean tasks are approved');
    }

    public function test_tutor_has_read_only_scoped_source_routes_and_no_profile_edit(): void
    {
        $f = $this->f();
        $outside = Apprentice::factory()->create();
        $this->actingAs($f['tutor'])->get('/record-checks')->assertOk()->assertSee($f['a']->reference)->assertDontSee($outside->reference);
        $this->get('/apprentices/'.$f['a']->id.'/record-checks')->assertOk()->assertDontSee(route('apprentices.edit', $f['a']), false)->assertSee('Ask an administrator');
        $this->get('/apprentices/'.$outside->id.'/record-checks')->assertForbidden();
    }

    #[TestWith(['apprentice'])]
    #[TestWith(['employer'])]
    #[TestWith(['mentor'])]
    #[TestWith(['careers_adviser'])]
    #[TestWith(['inclusion_lead'])]
    #[TestWith(['functional_skills_tutor'])]
    public function test_other_roles_cannot_open_staff_record_checks(string $role): void
    {
        $f = $this->f();
        $u = User::factory()->create(['role' => $role]);
        $this->actingAs($u)->get('/record-checks')->assertForbidden();
        $this->get('/apprentices/'.$f['a']->id.'/record-checks')->assertForbidden();
    }

    public function test_missing_filter_counts_apply_after_current_tutor_scope(): void
    {
        $f = $this->f();
        $p = Programme::factory()->create();
        Apprentice::factory()->create(['tutor_id' => $f['tutor']->id, 'programme_id' => $p->id]);
        Apprentice::factory()->create(['programme_id' => null]);
        $this->actingAs($f['tutor'])->get('/record-checks?check=programme')->assertOk()->assertViewHas('records', fn ($p) => $p->total() === 1)->assertSee($f['a']->reference);
    }

    public function test_pagination_is_bounded_and_keeps_missing_filter(): void
    {
        $f = $this->f();
        Apprentice::factory()->count(22)->create(['tutor_id' => $f['tutor']->id, 'user_id' => null]);
        $this->actingAs($f['tutor'])->get('/record-checks?check=account&page=2')->assertOk()->assertViewHas('records', fn ($p) => $p->total() === 23 && count($p->items()) === 3)->assertSee('check=account', false);
    }

    #[TestWith(['check=bad'])]
    #[TestWith(['check[]=all'])]
    #[TestWith(['page=-1'])]
    #[TestWith(['search[]=bad'])]
    public function test_invalid_filter_inputs_are_rejected(string $query): void
    {
        $f = $this->f();
        $this->actingAs($f['admin'])->getJson('/record-checks?'.$query)->assertUnprocessable();
    }

    public function test_help_guides_and_grouped_navigation_match_new_routes(): void
    {
        $f = $this->f();
        $this->actingAs($f['admin'])->get('/record-checks')->assertOk()->assertSee(route('help.index'),false)->assertSee('data-navigation-group="training"',false);
        $this->get('/quick-actions')->assertOk()->assertSee(route('help.index'),false);
    }
}
