<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\SupportConcern;
use App\Models\SupportPlan;
use App\Models\TeachingInstruction;
use App\Models\TutorGroup;
use App\Models\User;
use App\Support\LearningAccess;
use App\Support\SupportAccess;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SupportCapabilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role = 'admin'): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->save();

        return $u;
    }

    private function grant(User $u, array $data = []): void
    {
        $this->post('/staff-support-permissions/'.$u->id, ['version' => 0, 'duty' => 'member', 'scope' => 'assigned', 'reason' => 'Fictional cover arrangement', ...$data])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_admin_grants_reopens_and_revokes_with_audit_and_stale_guard(): void
    {
        $admin = $this->actor();
        $t = $this->actor('tutor');
        $a = Apprentice::factory()->create(['tutor_id' => $t->id]);
        $this->actingAs($admin)->get('/staff-support-permissions?person='.$t->id)->assertOk()->assertSee('Inclusion Team duty');
        $this->grant($t);
        $this->assertTrue(SupportAccess::team($t, $a));
        $this->assertSame('tutor', $t->fresh()->role);
        $this->assertSame(2, $t->fresh()->record_version);
        $this->get('/staff-support-permissions?person='.$t->id)->assertOk()->assertSee('Fictional cover arrangement');
        $this->post('/staff-support-permissions/'.$t->id, ['version' => 0, 'duty' => 'staff', 'scope' => 'assigned', 'reason' => 'Stale'])->assertSessionHasErrors('version');
        $this->assertTrue(SupportAccess::team($t, $a));
        $this->grant($t, ['version' => 1, 'duty' => 'staff']);
        $this->assertFalse(SupportAccess::team($t, $a));
        $this->assertDatabaseCount('support_permission_events', 2);
        $this->actingAs($t)->get('/support')->assertForbidden();
    }

    #[TestWith(['tutor'])] #[TestWith(['inclusion_lead'])] #[TestWith(['inclusion_member'])] #[TestWith(['apprentice'])] #[TestWith(['employer'])] #[TestWith(['manager'])]
    public function test_only_administrators_can_edit_support_permissions(string $role): void
    {
        $target = $this->actor('staff');
        $this->actingAs($this->actor($role))->get('/staff-support-permissions')->assertForbidden();
        $this->post('/staff-support-permissions/'.$target->id, [])->assertForbidden();
        $this->assertDatabaseCount('support_capabilities', 0);
    }

    public function test_assigned_scope_uses_current_links_and_explicit_groups_without_leaking_other_cases(): void
    {
        $t = $this->actor('tutor');
        $g = TutorGroup::factory()->create();
        $own = Apprentice::factory()->create(['tutor_id' => $t->id]);
        $extra = Apprentice::factory()->create();
        $grouped = Apprentice::factory()->create(['tutor_group_id' => $g->id]);
        $outside = Apprentice::factory()->create();
        foreach ([$own, $extra, $grouped, $outside] as $a) {
            SupportConcern::factory()->create(['apprentice_id' => $a->id, 'initial_note' => 'Fictional case '.$a->id]);
        }
        $this->actingAs($this->actor());
        $this->grant($t, ['apprentice_ids' => [$extra->id], 'group_ids' => [$g->id]]);
        $this->actingAs($t)->get('/support')->assertOk()->assertSee($own->reference)->assertSee($extra->reference)->assertSee($grouped->reference)->assertDontSee($outside->reference);
        $this->get('/apprentices/'.$outside->id.'/support')->assertForbidden();
        $this->post('/concerns/'.SupportConcern::where('apprentice_id', $outside->id)->value('id').'/followup', [])->assertForbidden();
        $own->tutor_id = null;
        $own->save();
        $this->assertFalse(SupportAccess::team($t, $own));
        $this->get('/apprentices/'.$own->id.'/support')->assertForbidden();
    }

    public function test_team_duty_does_not_grant_careers_hr_or_subject_scope(): void
    {
        $t = $this->actor('tutor');
        $a = Apprentice::factory()->create(['tutor_id' => $t->id]);
        $other = $this->actor('staff');
        $this->actingAs($this->actor());
        $this->grant($t, ['scope' => 'all']);
        $this->actingAs($t)->get('/apprentices/'.$a->id.'/careers')->assertForbidden();
        $this->get('/staff-development/'.$other->id)->assertForbidden();
        $this->assertFalse(LearningAccess::subject($t, $a, 'maths'));
    }

    public function test_single_active_lead_is_enforced_through_both_editors(): void
    {
        $this->actor('inclusion_lead');
        $t = $this->actor('staff');
        $this->actingAs($this->actor());
        $this->post('/staff-support-permissions/'.$t->id, ['version' => 0, 'duty' => 'lead', 'scope' => 'all', 'reason' => 'Duplicate lead'])->assertSessionHasErrors('duty');
        $this->assertDatabaseCount('support_capabilities', 0);
        $this->assertDatabaseCount('support_permission_events', 0);
        $data = ['name' => 'Fictional extra lead', 'email' => 'extra-lead@example.test', 'role' => 'inclusion_lead', 'active' => 1];
        $this->post('/directories/staff', $data)->assertSessionHasErrors('duty');
        $this->assertDatabaseMissing('users', ['email' => $data['email']]);
        $this->put('/directories/staff/'.$t->id, [...$data, 'record_version' => 1])->assertSessionHasErrors('duty');
        $this->assertSame('staff', $t->fresh()->role);
    }

    public function test_lead_replacement_requires_removing_old_duty_and_inactive_permissions_can_be_edited(): void
    {
        $old = $this->actor('inclusion_lead');
        $new = $this->actor('tutor');
        $this->actingAs($this->actor());
        $this->grant($old, ['duty' => 'staff', 'scope' => 'all']);
        $this->grant($new, ['duty' => 'lead', 'scope' => 'all']);
        $new->active = false;
        $new->save();
        $this->assertFalse(SupportAccess::team($new));
        $this->get('/staff-support-permissions?person='.$new->id)->assertOk()->assertSee('Inactive account');
        $this->grant($new, ['version' => 1, 'duty' => 'staff', 'scope' => 'all']);
        $this->assertSame('staff', SupportAccess::settings($new)['duty']);
    }

    public function test_legacy_team_scope_retained_until_explicit_revocation_and_no_tutor_instructions_for_ordinary_admin(): void
    {
        $lead = $this->actor('inclusion_lead');
        $a = Apprentice::factory()->create();
        $this->assertTrue(SupportAccess::approver($lead, $a));
        $admin = $this->actor();
        $this->assertTrue(SupportAccess::lead($admin, $a));
        $this->assertFalse(SupportAccess::writer($admin, $a));
        $this->actingAs($admin);
        $this->grant($lead, ['duty' => 'staff', 'scope' => 'all']);
        $this->actingAs($lead)->get('/support')->assertForbidden();
    }

    public function test_disallowed_targets_invalid_assignments_and_admin_scope_fail_without_audit(): void
    {
        $admin = $this->actor();
        $apprentice = $this->actor('apprentice');
        $t = $this->actor('staff');
        $this->actingAs($admin);
        $data = ['version' => 0, 'duty' => 'member', 'scope' => 'all', 'reason' => 'Invalid'];
        $this->post('/staff-support-permissions/'.$apprentice->id, $data)->assertForbidden();
        $this->post('/staff-support-permissions/'.$admin->id, [...$data, 'scope' => 'assigned'])->assertSessionHasErrors('scope');
        $this->post('/staff-support-permissions/'.$t->id, [...$data, 'apprentice_ids' => [999999]])->assertSessionHasErrors('apprentice_ids.0');
        $this->assertDatabaseCount('support_permission_events', 0);
    }

    public function test_permission_audit_failure_rolls_back_grant_and_user_version(): void
    {
        $t = $this->actor('staff');
        $this->actingAs($this->actor());
        DB::listen(function (QueryExecuted $q): void {
            if (str_contains($q->sql, 'support_permission_events') && str_starts_with(strtolower($q->sql), 'insert')) {
                throw new \RuntimeException('Deliberate fictional permission audit failure');
            }
        });
        $this->post('/staff-support-permissions/'.$t->id, ['version' => 0, 'duty' => 'member', 'scope' => 'all', 'reason' => 'Test rollback'])->assertStatus(500);
        $this->assertDatabaseCount('support_capabilities', 0);
        $this->assertSame(1, $t->fresh()->record_version);
    }

    public function test_private_plan_survey_and_download_routes_are_scoped_after_revocation(): void
    {
        $t = $this->actor('staff');
        $a = Apprentice::factory()->create();
        $c = SupportConcern::factory()->create(['apprentice_id' => $a->id]);
        $p = SupportPlan::create(['apprentice_id' => $a->id, 'concern_ids' => [$c->id], 'draft' => []]);
        $this->actingAs($this->actor());
        $this->grant($t, ['apprentice_ids' => [$a->id]]);
        $this->actingAs($t)->get('/apprentices/'.$a->id.'/support-plans/'.$p->id)->assertOk();
        $this->get('/apprentices/'.$a->id.'/surveys')->assertOk();
        $this->actingAs($this->actor());
        $this->grant($t, ['version' => 1, 'duty' => 'staff']);
        $this->actingAs($t)->get('/apprentices/'.$a->id.'/support-plans/'.$p->id)->assertForbidden();
        $this->get('/apprentices/'.$a->id.'/surveys')->assertForbidden();
        $this->get('/support-plans/'.$p->id.'/files/missing')->assertForbidden();
        $this->post('/apprentices/'.$a->id.'/support-plans/'.$p->id, [])->assertForbidden();
    }

    public function test_custom_member_drafts_and_custom_lead_classifies_and_releases_exact_instruction(): void
    {
        $admin = $this->actor();
        $member = $this->actor('staff');
        $lead = $this->actor('staff');
        $tutor = $this->actor('tutor');
        $a = Apprentice::factory()->create(['tutor_id' => $tutor->id]);
        $c = SupportConcern::factory()->create(['apprentice_id' => $a->id]);
        $this->actingAs($admin);
        $this->grant($member, ['apprentice_ids' => [$a->id]]);
        $this->grant($lead, ['duty' => 'lead', 'apprentice_ids' => [$a->id]]);
        $this->actingAs($member)->get('/apprentices/'.$a->id.'/support')->assertOk()->assertSee('Draft a new instruction for tutors');
        $this->post('/concerns/'.$c->id.'/classify', [])->assertForbidden();
        $this->post('/apprentices/'.$a->id.'/instructions', ['draft_text' => 'Approved tutor adjustment only', 'private_note' => 'Fictional private team context', 'recipient_ids' => [$tutor->id]])->assertRedirect()->assertSessionHasNoErrors();
        $instruction = TeachingInstruction::firstOrFail();
        $this->post('/instructions/'.$instruction->id.'/release', [])->assertForbidden();
        $this->actingAs($lead)->post('/concerns/'.$c->id.'/classify', ['version' => 1, 'code' => 'IM36', 'owner_id' => $member->id, 'next_action' => 'Check travel options', 'action_due' => today()->addDays(2)->toDateString(), 'next_review' => today()->addDays(7)->toDateString()])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/instructions/'.$instruction->id.'/release', ['version' => 1, 'confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($tutor)->get('/apprentices/'.$a->id.'/instructions')->assertOk()->assertSee('Approved tutor adjustment only')->assertDontSee('Fictional private team context');
    }

    public function test_reactivating_a_former_lead_cannot_bypass_the_single_lead_check(): void
    {
        $old = $this->actor('inclusion_lead');
        $old->active = false;
        $old->save();
        $this->actor('inclusion_lead');
        $this->actingAs($this->actor())->put('/directories/staff/'.$old->id, ['record_version' => 1, 'name' => $old->name, 'email' => $old->email, 'role' => 'inclusion_lead', 'active' => 1])->assertSessionHasErrors('duty');
        $this->assertFalse($old->fresh()->active);
    }
}
