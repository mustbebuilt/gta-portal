<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\Employer;
use App\Models\Programme;
use App\Models\TutorGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class LinkedApprenticeTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role = 'admin', ?string $scope = null): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->subject_scope = $scope;
        $u->save();

        return $u;
    }

    private function data(array $extra = []): array
    {
        return [...['reference' => 'LINK-001', 'first_name' => 'Fictional', 'last_name' => 'Linked Learner'], ...$extra];
    }

    public function test_all_directory_and_staff_links_save_and_reopen(): void
    {
        $e = Employer::factory()->create();
        $p = Programme::factory()->create();
        $g = TutorGroup::factory()->create();
        $links = ['employer_id' => $e->id, 'programme_id' => $p->id, 'tutor_group_id' => $g->id, 'tutor_id' => $this->actor('tutor')->id, 'mentor_id' => $this->actor('mentor')->id, 'careers_adviser_id' => $this->actor('careers_adviser')->id, 'maths_tutor_id' => $this->actor('functional_skills_tutor', 'maths')->id, 'english_tutor_id' => $this->actor('functional_skills_tutor', 'english')->id];
        $this->actingAs($this->actor())->post('/apprentices', $this->data($links))->assertRedirect()->assertSessionHasNoErrors();
        $a = Apprentice::firstOrFail();
        $this->assertDatabaseHas('apprentices', ['id' => $a->id, ...$links]);
        $this->get('/apprentices/'.$a->id)->assertOk()->assertSee($e->name)->assertSee($p->name)->assertSee($g->name);
        $this->assertNull($a->employer);
        $this->assertNull($a->programme);
    }

    #[TestWith(['maths_tutor_id', 'english'])]
    #[TestWith(['english_tutor_id', 'maths'])]
    public function test_specialist_assignment_refuses_the_wrong_subject(string $field, string $wrong): void
    {
        $u = $this->actor('functional_skills_tutor', $wrong);
        $this->actingAs($this->actor())->post('/apprentices', $this->data([$field => $u->id]))->assertSessionHasErrors($field);
        $this->assertDatabaseCount('apprentices', 0);
    }

    #[TestWith(['mentor_id', 'tutor'])]
    #[TestWith(['careers_adviser_id', 'mentor'])]
    #[TestWith(['tutor_id', 'careers_adviser'])]
    public function test_staff_assignment_refuses_the_wrong_role(string $field, string $wrong): void
    {
        $u = $this->actor($wrong);
        $this->actingAs($this->actor())->post('/apprentices', $this->data([$field => $u->id]))->assertSessionHasErrors($field);
        $this->assertDatabaseCount('apprentices', 0);
    }

    public function test_unknown_ids_are_rejected(): void
    {
        $this->actingAs($this->actor())->post('/apprentices', $this->data(['employer_id' => 99999, 'programme_id' => 99999, 'tutor_group_id' => 99999]))->assertSessionHasErrors(['employer_id', 'programme_id', 'tutor_group_id']);
        $this->assertDatabaseCount('apprentices', 0);
    }

    public function test_inactive_links_are_retained_but_cannot_be_newly_assigned(): void
    {
        $e = Employer::factory()->create(['active' => false]);
        $p = Programme::factory()->create(['active' => false]);
        $g = TutorGroup::factory()->create(['active' => false]);
        $links = ['employer_id' => $e->id, 'programme_id' => $p->id, 'tutor_group_id' => $g->id];
        $a = Apprentice::factory()->create(['reference' => 'LINK-001', ...$links]);
        $this->actingAs($this->actor());
        $this->get('/apprentices/'.$a->id.'/edit')->assertOk()->assertSee('inactive, current selection');
        $this->put('/apprentices/'.$a->id, $this->data([...$links, 'version' => 1]))->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/apprentices', $this->data([...$links, 'reference' => 'LINK-002']))->assertSessionHasErrors(array_keys($links));
        $this->assertDatabaseHas('apprentices', ['id' => $a->id, ...$links]);
    }

    public function test_group_membership_does_not_silently_assign_tutor_or_expand_access(): void
    {
        $tutor = $this->actor('tutor');
        $g = TutorGroup::factory()->create(['tutor_id' => $tutor->id]);
        $this->actingAs($this->actor())->post('/apprentices', $this->data(['tutor_group_id' => $g->id]))->assertRedirect()->assertSessionHasNoErrors();
        $a = Apprentice::first();
        $this->assertNull($a->tutor_id);
        $this->actingAs($tutor)->get('/apprentices/'.$a->id)->assertForbidden();
    }

    public function test_group_change_does_not_reassign_existing_apprentices(): void
    {
        $old = $this->actor('tutor');
        $new = $this->actor('tutor');
        $g = TutorGroup::factory()->create(['tutor_id' => $old->id]);
        $a = Apprentice::factory()->create(['tutor_id' => $old->id, 'tutor_group_id' => $g->id]);
        $this->actingAs($this->actor())->put('/directories/groups/'.$g->id, ['reference' => $g->reference, 'name' => $g->name, 'tutor_id' => $new->id, 'active' => 1, 'record_version' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($old->id, $a->fresh()->tutor_id);
        $this->actingAs($new)->get('/apprentices/'.$a->id)->assertForbidden();
    }

    public function test_old_text_is_preserved_until_confirmed_mapping(): void
    {
        $a = Apprentice::factory()->create(['reference' => 'LINK-001', 'programme' => 'Earlier paper programme', 'employer' => 'Earlier employer', 'group_name' => 'Earlier group']);
        $p = Programme::factory()->create();
        $this->actingAs($this->actor())->get('/apprentices/'.$a->id.'/edit')->assertOk()->assertSee('Previous unlinked entry: Earlier paper programme');
        $this->put('/apprentices/'.$a->id, $this->data(['programme_id' => $p->id, 'version' => 1]))->assertRedirect()->assertSessionHasNoErrors();
        $a->refresh();
        $this->assertSame('Earlier paper programme', $a->programme);
        $this->assertSame('Earlier employer', $a->employer);
        $this->assertSame($p->name, $a->programmeLabel());
    }

    public function test_changed_subject_scope_is_flagged_without_clearing_link(): void
    {
        $t = $this->actor('functional_skills_tutor', 'maths');
        $a = Apprentice::factory()->create(['maths_tutor_id' => $t->id]);
        $t->subject_scope = 'english';
        $t->save();
        $this->actingAs($this->actor())->get('/apprentices/'.$a->id.'/edit')->assertOk()->assertSee('current selection, role/scope changed');
        $this->assertSame($t->id, $a->fresh()->maths_tutor_id);
    }
}
