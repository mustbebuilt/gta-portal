<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\Employer;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class DirectoryManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role = 'admin'): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->save();

        return $u;
    }

    private function payload(string $kind): array
    {
        return match ($kind) {
            'employers' => ['reference' => 'EMP-001', 'name' => 'Fictional Employer', 'active' => 1], 'programmes' => ['standard_code' => 'TEST-01', 'version' => 'demo-1', 'name' => 'Fictional Programme', 'level' => 2, 'active' => 1], 'groups' => ['reference' => 'GRP-001', 'name' => 'Fictional Group', 'active' => 1], 'staff' => ['name' => 'Fictional Tutor', 'email' => 'new-tutor@example.test', 'role' => 'tutor', 'active' => 1]
        };
    }

    #[TestWith(['employers', 'employers'])]
    #[TestWith(['programmes', 'programmes'])]
    #[TestWith(['groups', 'tutor_groups'])]
    #[TestWith(['staff', 'users'])]
    public function test_admin_can_create_reopen_edit_and_reject_stale_directory_updates(string $kind, string $table): void
    {
        $this->actingAs($this->actor());
        $data = $this->payload($kind);
        $this->get('/directories/'.$kind.'/create')->assertOk();
        $response = $this->post('/directories/'.$kind, $data)->assertRedirect()->assertSessionHasNoErrors();
        $id = DB::table($table)->where('name', $data['name'])->value('id');
        $response->assertRedirect('/directories/'.$kind.'/'.$id);
        $this->get('/directories/'.$kind.'/'.$id)->assertOk()->assertSee($data['name']);
        $this->get('/directories/'.$kind.'/'.$id.'/edit')->assertOk();
        $this->put('/directories/'.$kind.'/'.$id, [...$data, 'name' => 'Updated Directory Record', 'record_version' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas($table, ['id' => $id, 'name' => 'Updated Directory Record', 'record_version' => 2]);
        $this->put('/directories/'.$kind.'/'.$id, [...$data, 'name' => 'Stale Record', 'record_version' => 1])->assertSessionHasErrors('record_version');
        $this->assertDatabaseHas($table, ['id' => $id, 'name' => 'Updated Directory Record']);
        $this->assertDatabaseHas('directory_events', ['kind' => $kind, 'record_id' => $id, 'event' => 'created']);
    }

    #[TestWith(['tutor'])]
    #[TestWith(['apprentice'])]
    #[TestWith(['mentor'])]
    #[TestWith(['functional_skills_tutor'])]
    #[TestWith(['careers_adviser'])]
    #[TestWith(['employer'])]
    public function test_non_administrators_cannot_read_or_write_directories(string $role): void
    {
        $e = Employer::factory()->create();
        $this->actingAs($this->actor($role));
        $this->get('/directories/employers')->assertForbidden();
        $this->get('/directories/employers/'.$e->id)->assertForbidden();
        $this->post('/directories/employers', $this->payload('employers'))->assertForbidden();
        $this->assertDatabaseCount('employers', 1);
    }

    public function test_guest_directory_access_requires_sign_in(): void
    {
        $this->get('/directories/staff')->assertRedirect('/login');
    }

    public function test_ceo_can_manage_directory_records(): void
    {
        $this->actingAs($this->actor('ceo'))->post('/directories/employers', $this->payload('employers'))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('employers', 1);
    }

    public function test_staff_directory_cannot_create_or_modify_protected_administrators(): void
    {
        $ceo = $this->actor('ceo');
        $this->actingAs($this->actor());
        $this->post('/directories/staff', [...$this->payload('staff'), 'role' => 'ceo'])->assertSessionHasErrors('role');
        $this->get('/directories/staff/'.$ceo->id.'/edit')->assertNotFound();
        $this->put('/directories/staff/'.$ceo->id, [...$this->payload('staff'), 'record_version' => 1])->assertNotFound();
        $this->assertSame('ceo', $ceo->fresh()->role);
    }

    public function test_subject_scope_is_required_for_functional_skills_staff(): void
    {
        $this->actingAs($this->actor())->post('/directories/staff', [...$this->payload('staff'), 'role' => 'functional_skills_tutor'])->assertSessionHasErrors('subject_scope');
        $this->post('/directories/staff', [...$this->payload('staff'), 'role' => 'functional_skills_tutor', 'subject_scope' => 'maths'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => 'new-tutor@example.test', 'subject_scope' => 'maths']);
    }

    public function test_posted_password_cannot_set_staff_credentials(): void
    {
        $this->actingAs($this->actor())->post('/directories/staff', [...$this->payload('staff'), 'password' => 'Known-attacker-password', 'subject_scope' => 'both'])->assertRedirect()->assertSessionHasNoErrors();
        $u = User::where('email', 'new-tutor@example.test')->firstOrFail();
        $this->assertFalse(Hash::check('Known-attacker-password', $u->password));
        $this->assertNull($u->subject_scope);
    }

    public function test_programme_code_can_have_distinct_versions_but_not_duplicate_same_version(): void
    {
        $this->actingAs($this->actor())->post('/directories/programmes', $this->payload('programmes'))->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/directories/programmes', [...$this->payload('programmes'), 'version' => 'demo-2'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/directories/programmes', $this->payload('programmes'))->assertSessionHasErrors('standard_code');
        $this->assertDatabaseCount('programmes', 2);
    }

    public function test_employer_reference_normalisation_prevents_duplicates(): void
    {
        $this->actingAs($this->actor())->post('/directories/employers', $this->payload('employers'));
        $this->post('/directories/employers', [...$this->payload('employers'), 'reference' => ' emp-001 '])->assertSessionHasErrors('reference');
        $this->assertDatabaseCount('employers', 1);
    }

    public function test_deactivation_preserves_assignments_and_edit_selection(): void
    {
        $e = Employer::factory()->create(['reference' => 'EMP-001']);
        $a = Apprentice::factory()->create(['employer_id' => $e->id]);
        $this->actingAs($this->actor());
        $this->put('/directories/employers/'.$e->id, [...$this->payload('employers'), 'active' => 0, 'record_version' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($e->id, $a->fresh()->employer_id);
        $this->get('/apprentices/'.$a->id.'/edit')->assertOk()->assertSee('inactive, current selection');
        $this->get('/directories/employers/'.$e->id)->assertOk()->assertSee('Inactive');
        $this->get('/directories/employers?state=active')->assertOk()->assertDontSee('EMP-001');
    }

    public function test_directory_text_is_escaped_and_unknown_kind_is_not_resolved(): void
    {
        $e = Employer::factory()->create(['name' => '<script>bad()</script>', 'contact_phone' => '<img src=x onerror=bad()>']);
        $this->actingAs($this->actor())->get('/directories/employers/'.$e->id)->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>bad()</script>', false)->assertSee('&lt;img', false);
        $this->get('/directories/users')->assertNotFound();
    }

    public function test_directory_audit_failure_rolls_back_creation(): void
    {
        $this->actingAs($this->actor());
        DB::listen(function (QueryExecuted $q): void {
            if (str_contains($q->sql, 'directory_events') && str_starts_with(strtolower($q->sql), 'insert')) {
                throw new \RuntimeException('Deliberate test-only audit failure');
            }
        });
        $this->post('/directories/employers', $this->payload('employers'))->assertStatus(500);
        $this->assertDatabaseCount('employers', 0);
    }
}
