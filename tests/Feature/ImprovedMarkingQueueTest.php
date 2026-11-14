<?php
namespace Tests\Feature;

use App\Models\{Apprentice,LearningTask,MarkingReminder,User};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ImprovedMarkingQueueTest extends TestCase
{
    use LazilyRefreshDatabase;
    protected function setUp(): void {parent::setUp();$this->travelTo(\Carbon\Carbon::parse('2026-11-13 12:00:00'));}
    private function actor(string $role='tutor',bool $active=true): User {return User::factory()->create(['role'=>$role,'active'=>$active]);}
    private function pending(?User $tutor,?\Carbon\Carbon $submitted=null,string $name='Fictional queue task'): LearningTask
    {
        $a=Apprentice::factory()->create(['tutor_id'=>$tutor?->id]);$t=LearningTask::factory()->create(['apprentice_id'=>$a->id,'reference'=>'queue-'.uniqid(),'title'=>$name]);$t->status='Awaiting marking';$t->submitted_at=$submitted;$t->submissions=$submitted?[['version'=>1,'submitted_at'=>$submitted->toIso8601String(),'template_hash'=>$t->template['sha256'],'answers'=>['secret'=>'PRIVATE ANSWER NOT FOR QUEUE'],'decision'=>null]]:[];$t->save();return $t;
    }
    public function test_due_today_uses_existing_deadline_and_excludes_overdue_missing_future_and_tomorrow(): void
    {
        $u=$this->actor();$today=$this->pending($u,now()->subDays(7),'Exact deadline');$this->pending($u,now()->subDays(7)->subSecond(),'Already overdue');$this->pending($u,null,'Missing date');$this->pending($u,now()->addDay(),'Future submission');$this->pending($u,today()->addDay()->subDays(7),'Tomorrow deadline');
        $r=$this->actingAs($u)->get('/marking?view=today')->assertOk()->assertSee('Exact deadline')->assertDontSee('Already overdue')->assertDontSee('PRIVATE ANSWER NOT FOR QUEUE');$this->assertSame(1,$r->viewData('tasks')->total());$this->assertSame(['awaiting'=>5,'overdue'=>1,'due_today'=>1,'date_checks'=>2,'owner_checks'=>0],$r->viewData('counts'));$this->assertTrue($r->viewData('tasks')->first()->is($today));$this->assertDatabaseCount('marking_reminders',0);
    }
    public function test_manager_tutor_and_search_filters_scope_summary_and_rows_together(): void
    {
        $one=$this->actor();$two=$this->actor();$a=$this->pending($one,now()->subDays(8),'Selected tutor task');$b=$this->pending($two,now()->subDays(8),'Other tutor task');$r=$this->actingAs($this->actor('admin'))->get('/marking?view=all&tutor='.$one->id.'&search='.$a->apprentice->reference)->assertOk()->assertSee('Selected tutor task')->assertDontSee('Other tutor task');$this->assertSame(1,$r->viewData('counts')['awaiting']);$this->assertSame(1,$r->viewData('tasks')->total());$this->get('/marking?tutor='.$one->id.'&search='.$b->apprentice->reference)->assertOk()->assertViewHas('counts',fn($c)=>$c['awaiting']===0);$this->assertDatabaseCount('marking_reminders',0);
    }
    public function test_tutor_cannot_widen_scope_with_another_tutor_or_unassigned_filter(): void
    {
        $one=$this->actor();$two=$this->actor();$this->pending($one,now()->subDays(8));$this->pending($two,now()->subDays(8),'Foreign work');$this->actingAs($one)->get('/marking?tutor='.$two->id)->assertForbidden();$this->get('/marking?tutor=unassigned')->assertForbidden();$this->get('/marking?tutor='.$one->id)->assertOk()->assertDontSee('Foreign work')->assertDontSee('id="marking-tutor"',false);$this->assertDatabaseCount('marking_reminders',0);
    }
    public function test_unassigned_inactive_wrong_role_flags_are_visible_without_creating_reminders(): void
    {
        $inactive=$this->actor(active:false);$wrong=$this->actor('mentor');$this->pending($inactive,now()->subDays(8),'Inactive assignment');$this->pending($wrong,now()->subDays(8),'Wrong-role assignment');$unassigned=$this->pending(null,now()->subDays(8),'Unassigned task');$this->actingAs($this->actor('admin'));$r=$this->get('/marking?view=needs-owner')->assertOk()->assertSee('Inactive account')->assertSee('Assigned account is not a tutor')->assertSee('Not assigned');$this->assertSame(3,$r->viewData('counts')['owner_checks']);$this->get('/marking?view=all&tutor=unassigned')->assertOk()->assertViewHas('tasks',fn($tasks)=>$tasks->total()===1 && $tasks->first()->is($unassigned));$this->assertDatabaseCount('marking_reminders',0);
    }
    public function test_global_reminder_control_explicitly_ignores_filters_and_original_deduplication_remains(): void
    {
        $one=$this->actor();$two=$this->actor();$a=$this->pending($one,now()->subDays(8));$b=$this->pending($two,now()->subDays(8));$this->actingAs($this->actor('admin'))->get('/marking?tutor='.$one->id)->assertOk()->assertSee('regardless of the filters above');$this->post('/marking/reminders',['tutor'=>$one->id])->assertRedirect();$this->assertDatabaseCount('marking_reminders',2);$this->post('/marking/reminders')->assertRedirect();$this->assertDatabaseCount('marking_reminders',2);$this->assertSame('Awaiting marking',$a->fresh()->status);$this->assertSame('Awaiting marking',$b->fresh()->status);
    }
    public function test_pagination_keeps_filters_and_completed_final_task_keeps_a_valid_empty_tutor_view(): void
    {
        $u=$this->actor();for($i=0;$i<26;$i++){$this->pending($u,now()->subDays(8)->subMinutes($i));}$this->actingAs($this->actor('admin'));$r=$this->get('/marking?view=all&tutor='.$u->id.'&page=99')->assertOk();$this->assertSame(2,$r->viewData('tasks')->currentPage());$this->assertCount(1,$r->viewData('tasks')->items());$this->assertStringContainsString('tutor='.$u->id,$r->viewData('tasks')->previousPageUrl());LearningTask::query()->update(['status'=>'Approved']);$r=$this->get('/marking?view=all&tutor='.$u->id)->assertOk()->assertSee('No submitted tasks match');$this->assertSame(0,$r->viewData('counts')['awaiting']);
    }
    public function test_invalid_manager_tutor_values_refuse_and_dst_calendar_deadline_view_stays_consistent(): void
    {
        $this->actingAs($this->actor('admin'))->get('/marking?tutor=unknown')->assertStatus(422);$this->getJson('/marking?tutor[]=invalid')->assertUnprocessable()->assertJsonValidationErrors('tutor');$this->travelTo(\Carbon\Carbon::parse('2026-10-26 12:00:00','Europe/London'));$u=$this->actor();$t=$this->pending($u,now()->subDays(7));$r=$this->get('/marking?view=today&tutor='.$u->id)->assertOk();$this->assertSame(1,$r->viewData('counts')['due_today']);$this->assertSame(0,$r->viewData('counts')['overdue']);$this->assertSame(1,$r->viewData('tasks')->total());$this->assertFalse($t->markingOverdue());
    }
}
