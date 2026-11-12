<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApprenticeManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_default_seed_does_not_create_known_password_accounts(): void
    {
        $this->seed();
        $this->assertDatabaseCount('users', 0);
    }

    private function actor(string $role = 'admin'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    private function fields(array $extra = []): array
    {
        return [...['reference' => 'TEST-001', 'first_name' => 'Fictional', 'last_name' => 'Learner'], ...$extra];
    }

    private function record(array $extra = []): Apprentice
    {
        return Apprentice::factory()->create($this->fields($extra));
    }

    public function test_guest_cannot_read_or_submit_apprentices(): void
    {
        $this->get('/apprentices')->assertRedirect('/login');
        $this->post('/apprentices', $this->fields())->assertRedirect('/login');
        $this->assertDatabaseCount('apprentices', 0);
    }

    public function test_admin_can_add_and_reopen_all_recorded_fields(): void
    {
        $admin = $this->actor();
        $tutor = $this->actor('tutor');
        $fields = $this->fields(['email' => 'learner@example.test', 'programme' => 'Fictional programme', 'start_date' => '2026-01-05', 'planned_end_date' => '2028-01-05', 'employer' => 'Demo Workshop', 'group_name' => 'Demo Group', 'tutor_id' => $tutor->id]);
        $this->actingAs($admin)->post('/apprentices', $fields)->assertRedirect()->assertSessionHasNoErrors();
        $record = Apprentice::firstOrFail();
        $this->assertSame(1, $record->version);
        foreach ($fields as $key => $value) {
            $actual = in_array($key, ['start_date', 'planned_end_date']) ? $record->$key->format('Y-m-d') : $record->$key;
            $this->assertSame($value, $actual);
        }
        $this->get('/apprentices/'.$record->id)->assertOk()->assertSee('Fictional Learner')->assertSee('Demo Workshop');
        $this->assertDatabaseHas('audit_events', ['apprentice_id' => $record->id, 'user_id' => $admin->id, 'event' => 'created']);
    }

    public function test_missing_optional_information_remains_null_and_not_rated(): void
    {
        $this->actingAs($this->actor())->post('/apprentices', $this->fields())->assertRedirect()->assertSessionHasNoErrors();
        $a = Apprentice::first();
        $this->assertNull($a->start_date);
        $this->assertNull($a->tutor_id);
        $this->get('/apprentices/'.$a->id)->assertSee('Not recorded')->assertDontSee('0%');
    }

    public function test_duplicate_reference_case_and_whitespace_do_not_create_another_record(): void
    {
        $this->record();
        $this->actingAs($this->actor())->post('/apprentices', $this->fields(['reference' => ' test-001 ']))->assertSessionHasErrors('reference');
        $this->assertDatabaseCount('apprentices', 1);
    }

    public function test_missing_names_and_invalid_email_are_rejected(): void
    {
        $this->actingAs($this->actor())->post('/apprentices', ['reference' => 'X', 'email' => 'not-email'])->assertSessionHasErrors(['first_name', 'last_name', 'email']);
        $this->assertDatabaseCount('apprentices', 0);
    }

    public function test_finish_date_before_start_is_rejected(): void
    {
        $this->actingAs($this->actor())->post('/apprentices', $this->fields(['start_date' => '2026-10-01', 'planned_end_date' => '2026-09-01']))->assertSessionHasErrors('planned_end_date');
        $this->assertDatabaseCount('apprentices', 0);
    }

    public function test_invalid_tutor_or_wrong_role_is_rejected(): void
    {
        $admin = $this->actor();
        $this->actingAs($admin)->post('/apprentices', $this->fields(['tutor_id' => $admin->id]))->assertSessionHasErrors('tutor_id');
        $this->post('/apprentices', $this->fields(['tutor_id' => 999999]))->assertSessionHasErrors('tutor_id');
    }

    public function test_edit_updates_same_record_and_increments_version(): void
    {
        $a = $this->record();
        $this->actingAs($this->actor())->put('/apprentices/'.$a->id, $this->fields(['last_name' => 'Updated', 'version' => 1]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Updated', $a->fresh()->last_name);
        $this->assertSame(2, $a->fresh()->version);
        $this->assertDatabaseCount('apprentices', 1);
    }

    public function test_stale_edit_cannot_overwrite_saved_changes(): void
    {
        $a = $this->record();
        $this->actingAs($this->actor())->put('/apprentices/'.$a->id, $this->fields(['last_name' => 'First edit', 'version' => 1]));
        $this->put('/apprentices/'.$a->id, $this->fields(['last_name' => 'Stale edit', 'version' => 1]))->assertSessionHasErrors('version');
        $this->assertSame('First edit', $a->fresh()->last_name);
    }

    public function test_tutor_sees_only_assigned_records_and_cannot_edit(): void
    {
        $tutor = $this->actor('tutor');
        $a = $this->record(['tutor_id' => $tutor->id]);
        $b = $this->record(['reference' => 'TEST-002', 'last_name' => 'Restricted']);
        $this->actingAs($tutor)->get('/apprentices')->assertOk()->assertSee('TEST-001')->assertDontSee('TEST-002');
        $this->get('/apprentices/'.$a->id)->assertOk();
        $this->get('/apprentices/'.$b->id)->assertForbidden();
        $this->get('/apprentices/'.$a->id.'/edit')->assertForbidden();
        $this->put('/apprentices/'.$a->id, $this->fields(['version' => 1]))->assertForbidden();
    }

    public function test_apprentice_sees_only_own_record_and_cannot_create(): void
    {
        $u = $this->actor('apprentice');
        $a = $this->record();
        $a->user_id = $u->id;
        $a->save();
        $b = $this->record(['reference' => 'TEST-002']);
        $this->actingAs($u)->get('/apprentices/'.$a->id)->assertOk();
        $this->get('/apprentices/'.$b->id)->assertForbidden();
        $this->get('/apprentices/create')->assertForbidden();
        $this->post('/apprentices', $this->fields())->assertForbidden();
    }

    public function test_unsupported_roles_are_denied(): void
    {
        $a = $this->record();
        $this->actingAs($this->actor('employer'))->get('/apprentices')->assertForbidden();
        $this->get('/apprentices/'.$a->id)->assertForbidden();
    }

    public function test_revoked_account_loses_access_even_with_a_session(): void
    {
        $u = $this->actor();
        $u->active = false;
        $u->save();
        $this->actingAs($u)->get('/apprentices')->assertForbidden();
    }

    public function test_untrusted_fields_cannot_grant_access_or_change_version(): void
    {
        $other = $this->actor('apprentice');
        $this->actingAs($this->actor())->post('/apprentices', $this->fields(['user_id' => $other->id, 'role' => 'ceo']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(Apprentice::first()->user_id);
        $this->assertSame('apprentice', $other->fresh()->role);
    }

    public function test_names_are_escaped_on_output(): void
    {
        $a = $this->record(['first_name' => '<script>alert(1)</script>']);
        $this->actingAs($this->actor())->get('/apprentices/'.$a->id)->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_search_is_scoped_and_handles_sql_looking_input(): void
    {
        $this->record();
        $this->actingAs($this->actor())->get('/apprentices?search='.urlencode("' OR 1=1 --"))->assertOk()->assertDontSee('TEST-001');
        $this->assertDatabaseCount('apprentices', 1);
    }

    public function test_audit_failure_rolls_back_record_creation(): void
    {
        $admin = $this->actor();
        DB::listen(function (QueryExecuted $query): void {
            if (str_contains($query->sql, 'audit_events') && str_starts_with(strtolower($query->sql), 'insert')) {
                throw new \RuntimeException('Deliberate test-only audit failure');
            }
        });
        $this->actingAs($admin)->post('/apprentices', $this->fields())->assertStatus(500);
        $this->assertDatabaseCount('apprentices', 0);
    }

    public function test_login_logout_and_bad_password(): void
    {
        $u = $this->actor();
        $u->password = 'Fictional-test-password-382!';
        $u->save();
        $this->post('/login', ['email' => $u->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => $u->email, 'password' => 'Fictional-test-password-382!'])->assertRedirect('/apprentices');
        $this->assertAuthenticatedAs($u);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_login_throttles_repeated_failures(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $response = $this->post('/login', ['email' => 'invalid@example.test', 'password' => 'wrong']);
        } $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many attempts', session('errors')->first('email'));
    }

    public function test_ceo_can_manage_core_records_without_new_private_permissions(): void
    {
        $this->actingAs($this->actor('ceo'))->post('/apprentices', $this->fields())->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/apprentices/'.Apprentice::first()->id.'/edit')->assertOk();
    }

    public function test_existing_inactive_assignment_is_not_silently_removed(): void
    {
        $tutor = $this->actor('tutor');
        $record = $this->record(['tutor_id' => $tutor->id]);
        $tutor->active = false;
        $tutor->save();
        $this->actingAs($this->actor())->get('/apprentices/'.$record->id.'/edit')->assertOk()->assertSee('inactive, current assignment');
        $this->put('/apprentices/'.$record->id, $this->fields(['version' => 1, 'tutor_id' => $tutor->id]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($tutor->id, $record->fresh()->tutor_id);
    }

    public function test_demo_setup_refuses_non_local_environments(): void
    {
        $this->artisan('gta:demo')->assertExitCode(1);
        $this->assertDatabaseCount('apprentices', 0);
    }

    public function test_local_demo_setup_refuses_to_overwrite_existing_records(): void
    {
        $this->app['env'] = 'local';
        $this->actor();
        $this->artisan('gta:demo')->assertExitCode(1);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_application_applies_security_headers_in_production(): void
    {
        $this->app['env'] = 'production';
        $response = $this->get('/login');
        $response->assertStatus(200);
        $response->assertHeader('Cache-Control', 'no-store, private');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'same-origin');
    }
}
