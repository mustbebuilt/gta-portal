<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\ExecutiveAccessSession;
use App\Models\TeachingInstruction;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExecutiveAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role = 'ceo'): User
    {
        $u = User::factory()->create(['password' => 'Fictional-CEO-test-only!']);
        $u->role = $role;
        $u->save();

        return $u;
    }

    private function begin(User $u): ExecutiveAccessSession
    {
        $this->actingAs($u)->post('/ceo/emergency', ['reason' => 'Inclusion lead unavailable; urgent teaching adjustment', 'password' => 'Fictional-CEO-test-only!', 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();

        return ExecutiveAccessSession::where('user_id', $u->id)->firstOrFail();
    }

    private function instructionFixture(): array
    {
        $t = $this->actor('tutor');
        $a = Apprentice::factory()->create(['tutor_id' => $t->id]);

        return [$a, ['draft_text' => 'Urgent practical teaching sequence', 'private_note' => 'Restricted source note', 'recipient_ids' => [$t->id]], $t];
    }

    public function test_ceo_must_explicitly_enable_with_reason_password_and_confirmation(): void
    {
        $u = $this->actor();
        [$a,$v] = $this->instructionFixture();
        $this->actingAs($u)->post('/apprentices/'.$a->id.'/instructions', $v)->assertForbidden();
        $this->post('/ceo/emergency', [])->assertSessionHasErrors(['reason', 'password', 'confirmed']);
        $this->post('/ceo/emergency', ['reason' => 'Fictional need', 'password' => 'incorrect', 'confirmed' => 1])->assertSessionHasErrors('password');
        $this->assertDatabaseCount('executive_access_sessions', 0);
        $session = $this->begin($u);
        $this->assertNull($session->ended_at);
        $this->assertDatabaseHas('executive_access_events', ['executive_access_session_id' => $session->id, 'action' => 'Emergency access enabled']);
        $this->get('/ceo/emergency')->assertOk()->assertSee('Emergency override on');
    }

    public function test_emergency_instruction_use_is_audited_and_retains_private_copy_separation(): void
    {
        $u = $this->actor();
        [$a,$v,$t] = $this->instructionFixture();
        $session = $this->begin($u);
        $this->post('/apprentices/'.$a->id.'/instructions', $v)->assertRedirect()->assertSessionHasNoErrors();
        $i = TeachingInstruction::firstOrFail();
        $this->post('/instructions/'.$i->id.'/release', ['version' => 1, 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('executive_access_events', ['executive_access_session_id' => $session->id, 'action' => 'instruction_drafted']);
        $this->assertDatabaseHas('executive_access_events', ['executive_access_session_id' => $session->id, 'action' => 'instruction_approved']);
        $this->actingAs($t)->get('/apprentices/'.$a->id.'/instructions')->assertOk()->assertSee('Urgent practical teaching sequence')->assertDontSee('Restricted source note');
    }

    public function test_ordinary_administrator_or_other_account_cannot_enable_or_reuse_ceo_session(): void
    {
        $u = $this->actor();
        $s = $this->begin($u);
        [$a,$v] = $this->instructionFixture();
        foreach (['admin', 'tutor', 'inclusion_member', 'apprentice'] as $role) {
            $other = $this->actor($role);
            $this->actingAs($other)->withSession(['gta_executive_reference' => $s->reference])->post('/ceo/emergency', ['reason' => 'Try', 'password' => 'Fictional-CEO-test-only!', 'confirmed' => 1])->assertForbidden();
            $this->get('/ceo/emergency')->assertForbidden();
        }
        $otherCEO = $this->actor();
        $this->actingAs($otherCEO)->withSession(['gta_executive_reference' => $s->reference])->post('/apprentices/'.$a->id.'/instructions', $v)->assertForbidden();
    }

    public function test_end_removes_elevation_without_deleting_history_or_ordinary_oversight(): void
    {
        $u = $this->actor();
        $s = $this->begin($u);
        [$a,$v] = $this->instructionFixture();
        $this->actingAs($u)->post('/ceo/emergency/end')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull($s->fresh()->ended_at);
        $this->post('/apprentices/'.$a->id.'/instructions', $v)->assertForbidden();
        $this->get('/apprentices')->assertOk();
        $this->get('/ceo/emergency')->assertOk()->assertSee('Emergency override off')->assertSee('Inclusion lead unavailable');
    }

    public function test_logout_ends_override_and_new_sign_in_does_not_restore_it(): void
    {
        $u = $this->actor();
        $s = $this->begin($u);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertNotNull($s->fresh()->ended_at);
        [$a,$v] = $this->instructionFixture();
        $this->actingAs($u)->post('/apprentices/'.$a->id.'/instructions', $v)->assertForbidden();
    }

    public function test_override_does_not_bypass_stale_source_checks_or_inactive_accounts(): void
    {
        $u = $this->actor();
        $s = $this->begin($u);
        [$a,$v] = $this->instructionFixture();
        $this->actingAs($u)->post('/apprentices/'.$a->id.'/instructions', $v)->assertRedirect()->assertSessionHasNoErrors();
        $i = TeachingInstruction::firstOrFail();
        $this->post('/instructions/'.$i->id.'/release', ['version' => 0, 'confirmed' => 1])->assertSessionHasErrors('version');
        $u->active = false;
        $u->save();
        $this->post('/instructions/'.$i->id.'/release', ['version' => 1, 'confirmed' => 1])->assertForbidden();
        $this->assertNull($i->fresh()->release);
    }

    public function test_emergency_audit_failure_rolls_back_restricted_action(): void
    {
        $u = $this->actor();
        $this->begin($u);
        [$a,$v] = $this->instructionFixture();
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'executive_access_events')) {
                throw new \RuntimeException('Deliberate emergency-use audit failure');
            }
        });
        $this->actingAs($u)->post('/apprentices/'.$a->id.'/instructions', $v)->assertStatus(500);
        $this->assertDatabaseCount('teaching_instructions', 0);
    }

    public function test_duplicate_enable_is_rejected_without_creating_another_session(): void
    {
        $u = $this->actor();
        $this->begin($u);
        $this->post('/ceo/emergency', ['reason' => 'Another reason', 'password' => 'Fictional-CEO-test-only!', 'confirmed' => 1])->assertSessionHasErrors('reason');
        $this->assertDatabaseCount('executive_access_sessions', 1);
    }
}
