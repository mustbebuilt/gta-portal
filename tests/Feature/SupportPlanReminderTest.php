<?php

namespace Tests\Feature;

use App\Models\{Apprentice, SupportCapability, SupportPlan, SupportPlanReminder, User};
use App\Support\{ReviewedImports, SupportPlanReminders};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupportPlanReminderTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(bool $assign = true): array
    {
        $lead = User::factory()->create(['role' => 'inclusion_lead']);
        $owner = User::factory()->create(['role' => 'inclusion_member']);
        $a = Apprentice::factory()->create();
        $p = SupportPlan::create(['apprentice_id' => $a->id, 'concern_ids'=>[], 'draft' => ['fields' => ['review' => '2030-01-01', 'support' => 'PRIVATE-DRAFT']]]);
        $p->status = 'Active';$p->reviews = [];
        $p->active_plan = ['approved_at' => now()->subDays(10)->toIso8601String(), 'fields' => [
            'review' => today()->subDay()->toDateString(), 'support' => 'PRIVATE-SUPPORT', 'barriers' => 'PRIVATE-BARRIER'], 'reason' => 'PRIVATE-REASON'];
        $p->save();
        if ($assign) { SupportPlanReminders::assign($p, $lead, 1, 1, $owner->id); }
        return [$p->fresh(), $lead, $owner, $a];
    }

    private function recorded(): array
    {
        $data = $this->fixture();SupportPlanReminders::generate(true);
        return [...$data, SupportPlanReminder::firstOrFail()];
    }

    public function test_default_preview_is_read_only_and_never_guesses_a_coordinator(): void
    {
        [$p] = $this->fixture(false);$before = $p->fresh()->getAttributes();
        $result = SupportPlanReminders::generate();
        $this->assertSame(1, $result['coordinator_checks']);$this->assertSame(0, $result['created']);
        $this->artisan('gta:support-plan-reminders')->assertExitCode(0);
        $this->assertDatabaseCount('support_plan_reminders', 0);
        $this->assertDatabaseCount('support_plan_reminder_events', 0);
        $this->assertSame($before, $p->fresh()->getAttributes());
        $this->assertFalse(config('portal_reminders.support_plan_scheduled'));
    }

    public function test_assignment_is_versioned_and_preserves_approved_content_reviews_and_dates(): void
    {
        [$p,$lead,$owner] = $this->fixture(false);$active = $p->active_plan;$draft = $p->draft;
        $this->actingAs($lead)->post('/support-plans/'.$p->id.'/review-coordinator', ['version' => 1, 'coordinator' => $owner->id])
            ->assertRedirect()->assertSessionHasNoErrors();
        $fresh = $p->fresh();$this->assertSame($owner->id, $fresh->review_owner_id);
        $this->assertSame(1, $fresh->review_owner_revision);$this->assertSame(2, $fresh->version);
        $this->assertSame($active, $fresh->active_plan);$this->assertSame($draft, $fresh->draft);$this->assertSame([], $fresh->reviews);
        $this->assertSame('review_coordinator_changed', $fresh->history[0]['event']);
        $this->post('/support-plans/'.$p->id.'/review-coordinator', ['version' => 1, 'coordinator' => $owner->id])->assertSessionHasErrors('coordinator');
        $this->assertDatabaseCount('support_plan_reminders', 0);
    }

    public function test_only_lead_or_authorised_administration_can_assign_and_owner_needs_actual_team_access(): void
    {
        [$p,$lead,$owner,$a] = $this->fixture(false);
        $this->actingAs($owner)->post('/support-plans/'.$p->id.'/review-coordinator', ['version' => 1, 'coordinator' => $owner->id])->assertForbidden();
        foreach (['tutor','employer','apprentice'] as $role) {
            $u = User::factory()->create(['role' => $role]);
            $this->actingAs($u)->post('/support-plans/'.$p->id.'/review-coordinator', ['version' => 1, 'coordinator' => $u->id])->assertForbidden();
            $this->get('/support-review-reminders')->assertForbidden();
        }
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($lead)->post('/support-plans/'.$p->id.'/review-coordinator', ['version' => 1, 'coordinator' => $admin->id])->assertSessionHasErrors('coordinator');
        SupportCapability::create(['user_id' => $owner->id, 'duty' => 'member', 'scope' => 'assigned', 'apprentice_ids' => [], 'group_ids' => []]);
        $this->post('/support-plans/'.$p->id.'/review-coordinator', ['version' => 1, 'coordinator' => $owner->id])->assertSessionHasErrors('coordinator');
        $owner->active = false;$owner->save();
        $this->post('/support-plans/'.$p->id.'/review-coordinator', ['version' => 1, 'coordinator' => $owner->id])->assertSessionHasErrors('coordinator');
        $this->assertNull($p->fresh()->review_owner_id);
    }

    public function test_recording_is_deduplicated_and_source_has_no_private_content(): void
    {
        [$p] = $this->fixture();$before = $p->fresh()->getAttributes();
        $this->assertSame(1, SupportPlanReminders::generate(true)['created']);
        $this->assertSame(1, SupportPlanReminders::generate(true)['existing']);
        $this->assertDatabaseCount('support_plan_reminders', 1);$this->assertDatabaseCount('support_plan_reminder_events', 1);
        $this->assertSame($before, $p->fresh()->getAttributes());
        $source = SupportPlanReminder::firstOrFail()->source;
        foreach (['PRIVATE-SUPPORT','PRIVATE-BARRIER','PRIVATE-DRAFT','PRIVATE-REASON'] as $text) {
            $this->assertStringNotContainsString($text, json_encode($source));
        }
        $this->assertSame(today()->subDay()->toDateString(), $source['due']);
    }

    public function test_seen_requires_current_recipient_and_changes_no_plan_review_or_funding_data(): void
    {
        [$p,$lead,$owner,$a,$r] = $this->recorded();$before = $p->fresh()->getAttributes();
        $this->actingAs($lead)->post('/support-review-reminders/'.$r->id.'/seen')->assertForbidden();
        $this->actingAs($owner)->get('/support-review-reminders')->assertOk()->assertSee($a->reference)->assertDontSee('PRIVATE-SUPPORT');
        $this->post('/support-review-reminders/'.$r->id.'/seen')->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/support-review-reminders/'.$r->id.'/seen')->assertRedirect();
        $this->assertNotNull($r->fresh()->seen_at);$this->assertNull($r->fresh()->dismissed_at);
        $this->assertDatabaseCount('support_plan_reminder_events', 2);
        $this->assertSame($before, $p->fresh()->getAttributes());
    }

    public function test_reassigning_back_creates_a_new_cycle_without_reusing_an_old_seen_record(): void
    {
        [$p,$lead,$owner,$a,$r] = $this->recorded();SupportPlanReminders::seen($r,$owner,1);
        $other = User::factory()->create(['role' => 'inclusion_member']);
        SupportPlanReminders::assign($p,$lead,1,$p->fresh()->version,$other->id);
        SupportPlanReminders::generate(true);
        SupportPlanReminders::assign($p,$lead,1,$p->fresh()->version,$owner->id);
        SupportPlanReminders::generate(true);
        $this->assertDatabaseCount('support_plan_reminders',3);
        $latest = SupportPlanReminder::latest('id')->first();
        $this->assertNotSame($r->source_key,$latest->source_key);$this->assertNull($latest->seen_at);
        $this->assertFalse(SupportPlanReminders::current($r,$owner));
    }

    public function test_draft_and_activity_changes_do_not_reset_the_active_source(): void
    {
        [$p,$lead,$owner,$a,$r] = $this->recorded();
        $p->draft = ['fields' => ['review' => '2035-01-01', 'support' => 'CHANGED PRIVATE DRAFT']];
        $p->activities = [['delivered' => 'PRIVATE ACTIVITY']];$p->version++;$p->save();
        $this->assertTrue(SupportPlanReminders::current($r,$owner));
        $this->assertSame(0,SupportPlanReminders::generate(true)['created']);
    }

    public function test_latest_exact_matching_review_controls_due_date_and_old_release_is_ignored(): void
    {
        [$p,$lead,$owner] = $this->fixture();
        $p->reviews = [['active_snapshot' => $p->active_plan, 'date' => today()->subDays(2)->toDateString(),
            'next' => today()->addDays(10)->toDateString(), 'notes' => 'PRIVATE REVIEW'],
            ['active_snapshot' => ['approved_at' => '2020-01-01T00:00:00+00:00'], 'next' => '2020-01-01']];$p->save();
        $this->assertSame('upcoming',SupportPlanReminders::position($p)['state']);
        $this->assertSame(0,SupportPlanReminders::generate(true)['created']);
    }

    public function test_new_approved_snapshot_or_formal_review_stales_the_old_prompt(): void
    {
        [$p,$lead,$owner,$a,$r] = $this->recorded();
        $p->reviews = [['active_snapshot'=>$p->active_plan,'date'=>today()->toDateString(),'next'=>today()->toDateString(),'notes'=>'PRIVATE-NEW-REVIEW']];$p->save();
        $this->assertFalse(SupportPlanReminders::current($r,$owner));SupportPlanReminders::generate(true);
        $this->assertDatabaseCount('support_plan_reminders',2);
        $new = $p->active_plan;$new['fields']['support'] = 'REAPPROVED PRIVATE SUPPORT';$p->active_plan=$new;$p->reviews=[];$p->save();
        SupportPlanReminders::generate(true);$this->assertDatabaseCount('support_plan_reminders',3);
    }

    public function test_invalid_or_future_dates_mismatched_snapshot_and_closed_plans_do_not_create_prompts(): void
    {
        foreach (['bad_date','future_approval','bad_review','mismatched_review','Closed','Draft'] as $case) {
            [$p] = $this->fixture();$active=$p->active_plan;
            if($case==='bad_date'){$active['fields']['review']='2026-02-30';$p->active_plan=$active;}
            if($case==='future_approval'){$active['approved_at']=now()->addDay()->toIso8601String();$p->active_plan=$active;}
            if($case==='bad_review'){$p->reviews=[['active_snapshot'=>$active,'date'=>'bad','next'=>today()->toDateString()]];}
            if($case==='mismatched_review'){$changed=$active;$changed['fields']['support']='DIFFERENT';$p->reviews=[['active_snapshot'=>$changed,'date'=>today()->toDateString(),'next'=>today()->toDateString()]];}
            if(in_array($case,['Closed','Draft'],true)){$p->status=$case;}
            $p->save();$this->assertNull(SupportPlanReminders::position($p)['source']);
        }
        $this->assertSame(0,SupportPlanReminders::generate(true)['created']);
    }

    public function test_scope_loss_hides_reminder_rows_and_pagination_totals(): void
    {
        [$p,$lead,$owner,$a,$r] = $this->recorded();
        SupportCapability::create(['user_id'=>$owner->id,'duty'=>'member','scope'=>'assigned','apprentice_ids'=>[],'group_ids'=>[]]);
        $response=$this->actingAs($owner)->get('/support-review-reminders')->assertOk()->assertDontSee($a->reference);
        $this->assertSame(0,$response->viewData('pages')->total());
        $this->post('/support-review-reminders/'.$r->id.'/seen')->assertForbidden();
        $this->post('/support-review-reminders/'.$r->id.'/dismiss')->assertForbidden();
    }

    public function test_only_outdated_owned_visible_prompts_can_be_dismissed(): void
    {
        [$p,$lead,$owner,$a,$r] = $this->recorded();
        $this->actingAs($owner)->post('/support-review-reminders/'.$r->id.'/dismiss')->assertForbidden();
        $p->status='Closed';$p->save();$before=$p->fresh()->getAttributes();
        $this->actingAs($lead)->post('/support-review-reminders/'.$r->id.'/dismiss')->assertForbidden();
        $this->actingAs($owner)->post('/support-review-reminders/'.$r->id.'/seen')->assertForbidden();
        $this->post('/support-review-reminders/'.$r->id.'/dismiss')->assertRedirect();
        $this->assertNotNull($r->fresh()->dismissed_at);$this->assertNull($r->fresh()->seen_at);
        $this->assertSame($before,$p->fresh()->getAttributes());
    }

    public function test_tampered_prompt_source_and_inactive_owner_are_not_current(): void
    {
        [$p,$lead,$owner,$a,$r] = $this->recorded();
        $source=$r->source;$source['due']='2020-01-01';$r->source=$source;$r->save();
        $this->assertFalse(SupportPlanReminders::current($r,$owner));
        $owner->active=false;$owner->save();$this->assertNull(SupportPlanReminders::position($p)['source']);
    }

    public function test_support_schedule_is_independent_opt_in_and_never_sends_external_messages(): void
    {
        $this->fixture();
        $event=collect(\Illuminate\Support\Facades\Schedule::events())->first(fn($event)=>str_contains($event->command??'','gta:support-plan-reminders'));
        $this->assertNotNull($event);$this->assertFalse($event->filtersPass($this->app));
        config(['portal_reminders.scheduled'=>true]);$this->assertFalse($event->filtersPass($this->app));
        config(['portal_reminders.support_plan_scheduled'=>true]);$this->assertTrue($event->filtersPass($this->app));
        $this->assertTrue($event->withoutOverlapping);$this->assertSame('5 9 * * *',$event->expression);
        $this->artisan('gta:support-plan-reminders',['--record'=>true])->expectsOutputToContain('"external_messages_sent":false')->assertSuccessful();
        $this->assertDatabaseCount('reminder_mail_outbox',0);
    }
}
