<?php

namespace Tests\Feature;

use App\Models\CareersPrompt;
use App\Support\CareersPrompts;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Tests\Concerns\CreatesOperationalPlan;
use Tests\TestCase;

class CareersCalendarPromptsTest extends TestCase
{
    use LazilyRefreshDatabase,CreatesOperationalPlan;

    public function test_current_plan_block_ten_and_eleven_prompts_preview_record_and_acknowledge_without_advice(): void
    {
        [$a,$admin,$ceo,$tutor,$learner,$adviser]=$this->approvedFixture();
        $preview=CareersPrompts::generate();$this->assertSame(2,$preview['eligible']);$this->assertSame(0,$preview['created']);$this->assertDatabaseCount('careers_prompts',0);
        $this->actingAs($admin)->get('/careers')->assertOk()->assertSee('Approved-calendar Careers checks')->assertSee('Block 10')->assertSee('Block 11');
        $this->post('/careers/prompts')->assertSessionHasNoErrors();$this->assertDatabaseCount('careers_prompts',2);$this->assertDatabaseCount('careers_prompt_events',2);
        $this->post('/careers/prompts')->assertSessionHasNoErrors();$this->assertDatabaseCount('careers_prompts',2);
        $p=CareersPrompt::firstOrFail();$this->actingAs($adviser)->get('/careers')->assertSee('Awaiting assigned adviser check');
        $this->post('/careers/prompts/'.$p->id.'/seen')->assertSessionHasNoErrors();$this->post('/careers/prompts/'.$p->id.'/seen')->assertSessionHasNoErrors();
        $this->assertNotNull($p->fresh()->seen_at);$this->assertDatabaseCount('careers_prompt_events',3);$this->assertDatabaseCount('career_discussions',0);$this->assertDatabaseCount('smart_actions',0);
        $this->actingAs($learner)->get('/careers')->assertOk()->assertDontSee('Approved-calendar Careers checks');
        $this->actingAs($tutor)->get('/careers')->assertForbidden();
    }

    public function test_reassignment_and_withdrawal_hide_old_prompts_and_reject_stale_seen(): void
    {
        [$a,$admin,$ceo,$tutor,$learner,$adviser]=$this->approvedFixture();CareersPrompts::generate(true);$old=CareersPrompt::firstOrFail();
        $replacement=$this->newActor('careers_adviser');$a->careers_adviser_id=$replacement->id;$a->save();
        $this->actingAs($adviser)->post('/careers/prompts/'.$old->id.'/seen')->assertForbidden();
        $this->get('/careers')->assertDontSee($a->reference);$this->actingAs($replacement)->get('/careers')->assertSee('Prompt not yet recorded');
        $counts=CareersPrompts::generate(true);$this->assertSame(2,$counts['created']);$this->assertDatabaseCount('careers_prompts',4);
        $new=CareersPrompt::where('recipient_id',$replacement->id)->first();
        $this->actingAs($admin)->post('/apprentices/'.$a->id.'/delivery-plan/withdraw',['version'=>3,'reason'=>'Source needs checking'])->assertSessionHasNoErrors();
        $this->actingAs($replacement)->post('/careers/prompts/'.$new->id.'/seen')->assertForbidden();
        $this->assertSame(0,CareersPrompts::generate()['due']);$this->assertDatabaseCount('careers_prompts',4);
    }

    public function test_inactive_wrong_and_unassigned_advisers_are_checks_not_recipients(): void
    {
        [$a,$admin,$ceo,$tutor,$learner,$adviser]=$this->approvedFixture();
        foreach([null,$tutor->id,$adviser->id] as $id) {
            $a->careers_adviser_id=$id;$a->save();$adviser->active=false;$adviser->save();
            $r=CareersPrompts::generate(true);$this->assertSame(2,$r['owner_checks']);$this->assertSame(0,$r['created']);
        }
        $this->assertDatabaseCount('careers_prompts',0);
        foreach([$tutor,$learner,$adviser] as $u){$this->actingAs($u)->post('/careers/prompts')->assertForbidden();}
    }

    public function test_only_started_calendar_blocks_qualify_and_unapproved_draft_dates_do_not_override(): void
    {
        [$a,$admin,$ceo,$tutor,$learner,$adviser,$plan]=$this->approvedFixture();
        $this->travelTo(today()->subDays(21)->setTime(12,0));$this->assertSame(0,CareersPrompts::generate()['due']);
        $this->travelBack();
        $draft=$plan->draft;foreach($draft['blocks'] as &$b){$b['start']=today()->addYear()->toDateString();$b['end']=today()->addYear()->addDay()->toDateString();}unset($b);$plan->draft=$draft;$plan->save();
        $this->assertSame(2,CareersPrompts::generate()['due']);
        $a->programmeRecord()->update(['active'=>false]);$this->assertSame(0,CareersPrompts::generate()['due']);
    }

    public function test_prompt_or_seen_audit_failure_rolls_back_source_and_acknowledgement(): void
    {
        [$a,$admin,$ceo,$tutor,$learner,$adviser]=$this->approvedFixture();
        \Tests\Support\AuditFault::install('reject_career_event','careers_prompt_events','Fictional audit failure');
        $this->actingAs($admin)->post('/careers/prompts')->assertStatus(500);$this->assertDatabaseCount('careers_prompts',0);
        DB::statement('DROP TRIGGER reject_career_event');CareersPrompts::generate(true);$p=CareersPrompt::first();
        \Tests\Support\AuditFault::install('reject_career_event','careers_prompt_events','Fictional audit failure');
        $this->actingAs($adviser)->post('/careers/prompts/'.$p->id.'/seen')->assertStatus(500);$this->assertNull($p->fresh()->seen_at);
    }

    public function test_combined_command_defaults_to_preview_schedule_is_opt_in_and_record_is_deduplicated(): void
    {
        $this->approvedFixture();
        $this->artisan('gta:portal-reminders')->assertSuccessful();$this->assertDatabaseCount('portal_reminder_runs',0);$this->assertDatabaseCount('careers_prompts',0);
        $events=collect(Schedule::events())->filter(fn($e)=>str_contains($e->command??'','gta:portal-reminders'));
        $this->assertCount(1,$events);$event=$events->first();$this->assertFalse($event->filtersPass($this->app));
        config(['portal_reminders.scheduled'=>true]);$this->assertTrue($event->filtersPass($this->app));$this->assertTrue($event->withoutOverlapping);$this->assertSame('0 9 * * *',$event->expression);
        $this->artisan('gta:portal-reminders',['--record'=>true])->assertSuccessful();$this->assertDatabaseCount('careers_prompts',2);$this->assertDatabaseCount('portal_reminder_runs',1);
        $this->artisan('gta:portal-reminders',['--record'=>true])->assertSuccessful();$this->assertDatabaseCount('careers_prompts',2);$this->assertDatabaseCount('portal_reminder_runs',2);
    }

    public function test_combined_run_failure_retains_no_partial_prompt_or_run_record(): void
    {
        [$a]=$this->approvedFixture();
        $task=\App\Models\LearningTask::factory()->create(['apprentice_id'=>$a->id,'reference'=>'b1-t4','template'=>\App\Support\TaskCatalogue::template('b1-t4')]);
        $task->status='Awaiting marking';$task->submitted_at=now()->subDays(8);$task->submissions=[['version'=>1,'submitted_at'=>$task->submitted_at->toIso8601String(),'template_hash'=>$task->template['sha256'],'decision'=>null]];$task->save();
        $this->assertSame(1,\App\Support\MarkingReminders::generate()['eligible']);
        \Tests\Support\AuditFault::install('reject_run_event','portal_reminder_runs','Fictional audit failure');
        try{$this->artisan('gta:portal-reminders',['--record'=>true])->run();$this->fail('Expected a failed run audit.');}catch(\Illuminate\Database\QueryException $e){$this->assertStringContainsString('Fictional audit failure',$e->getMessage());}
        $this->assertDatabaseCount('careers_prompts',0);$this->assertDatabaseCount('careers_prompt_events',0);$this->assertDatabaseCount('portal_reminder_runs',0);$this->assertDatabaseCount('marking_reminders',0);$this->assertDatabaseCount('marking_reminder_events',0);
    }
}
