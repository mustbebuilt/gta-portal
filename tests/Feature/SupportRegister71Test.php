<?php

namespace Tests\Feature;

use App\Models\{Apprentice, SupportMarkerSchedule71, SupportPlan, SupportSharedAction71, User};
use App\Support\SupportRegister71;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupportRegister71Test extends TestCase
{
    use LazilyRefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true, 'email_verified_at' => now()]);
    }

    public function test_calendar_interval_math_clamps_month_ends_and_caps_at_six_months(): void
    {
        $this->assertSame('2028-02-29', SupportRegister71::addInterval('2028-01-31', 'monthly'));
        $this->assertSame('2027-04-30', SupportRegister71::addInterval('2027-01-31', 'three_monthly'));
        $this->assertSame('2027-07-31', SupportRegister71::addInterval('2027-01-31', 'six_monthly'));
    }

    public function test_im1_to_im40_marker_workflow_formal_review_vs_discussion_and_shared_actions(): void
    {
        $admin = $this->admin();
        $tutor = User::factory()->create(['role' => 'tutor', 'active' => true, 'email_verified_at' => now()]);
        $apprenticeUser = User::factory()->create(['role' => 'apprentice', 'active' => true, 'email_verified_at' => now()]);
        $apprentice = Apprentice::factory()->create(['tutor_id' => $tutor->id]);

        $this->actingAs($admin)->withSession(['auth_version' => $admin->auth_version]);

        // IM40 requires brief description
        $this->post(route('support-register71.add-marker'), [
            'apprentice_id' => $apprentice->id,
            'code' => 'IM40',
            'interval_key' => 'monthly',
            'owner_id' => $admin->id,
            'note' => '',
        ])->assertSessionHasErrors('note');

        // Add valid IM1 marker with monthly interval — stays in Needs review before first formal review
        $this->post(route('support-register71.add-marker'), [
            'apprentice_id' => $apprentice->id,
            'code' => 'IM1',
            'interval_key' => 'monthly',
            'owner_id' => $admin->id,
            'note' => 'Initial dyslexia support marker',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(0, SupportPlan::count());
        $schedule = SupportMarkerSchedule71::firstOrFail();
        $initialDue = $schedule->next_due_date->toDateString();
        $this->assertFalse($schedule->first_review_completed);

        // Register shows apprentice in Needs review and Reports counts 1 distinct apprentice
        $this->get(route('support-register71.index', ['view' => 'needs_review']))->assertOk()->assertSee($apprentice->reference);
        $this->get(route('support-register71.reports'))->assertOk()->assertSee('IM1');

        // Discussion only does NOT advance due date or mark first review complete
        $this->post(route('support-register71.review', $schedule), [
            'version' => $schedule->version,
            'kind' => 'discussion_only',
            'note' => 'Brief catch-up in corridor',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $schedule->refresh();
        $this->assertFalse($schedule->first_review_completed);
        $this->assertSame($initialDue, $schedule->next_due_date->toDateString());

        // Formal review advances review anchor and drops apprentice out of Needs review
        $this->post(route('support-register71.review', $schedule), [
            'version' => $schedule->version,
            'kind' => 'formal_review',
            'review_date' => today()->toDateString(),
            'interval_key' => 'three_monthly',
            'note' => 'Formal review completed in register',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $schedule->refresh();
        $this->assertTrue($schedule->first_review_completed);
        $this->assertSame(SupportRegister71::addInterval(today()->toDateString(), 'three_monthly'), $schedule->next_due_date->toDateString());

        // Shared action is idempotent by request_uuid and completing it keeps schedule untouched
        $uuid = (string) Str::uuid();
        $this->post(route('support-register71.add-action'), [
            'apprentice_id' => $apprentice->id,
            'owner_id' => $admin->id,
            'due_date' => today()->addDays(7)->toDateString(),
            'summary' => 'Arrange reading overlay',
            'request_uuid' => $uuid,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('support-register71.add-action'), [
            'apprentice_id' => $apprentice->id,
            'owner_id' => $admin->id,
            'due_date' => today()->addDays(7)->toDateString(),
            'summary' => 'Arrange reading overlay',
            'request_uuid' => $uuid,
        ])->assertRedirect();
        $this->assertSame(1, SupportSharedAction71::count());

        $action = SupportSharedAction71::firstOrFail();
        $this->post(route('support-register71.complete-action', $action), ['outcome' => 'Overlay issued'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Completed', $action->fresh()->state);

        // Ordinary tutor sees meeting-safe register status for assigned apprentice, NOT private actions
        $this->actingAs($tutor)->withSession(['auth_version' => $tutor->auth_version]);
        $this->get(route('support-register71.index', ['view' => 'all']))->assertOk()
            ->assertSee($apprentice->reference)
            ->assertDontSee('Arrange reading overlay');

        // Apprentice cannot access register or reports
        $this->actingAs($apprenticeUser)->withSession(['auth_version' => $apprenticeUser->auth_version]);
        $this->get(route('support-register71.index'))->assertForbidden();
        $this->get(route('support-register71.reports'))->assertForbidden();
    }
}
