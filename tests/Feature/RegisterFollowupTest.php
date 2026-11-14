<?php
namespace Tests\Feature;

use App\Models\{Apprentice,AttendanceMark,TrainingSession,TutorGroup,User};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class RegisterFollowupTest extends TestCase
{
    use LazilyRefreshDatabase;
    protected function setUp(): void {parent::setUp();$this->travelTo(\Carbon\Carbon::parse('2026-11-13 12:00:00'));}
    private function actor(string $role='admin'): User {return User::factory()->create(['role'=>$role]);}
    private function fixture(int $days=1,?User $tutor=null): array
    {
        $tutor??=$this->actor('tutor');$g=TutorGroup::factory()->create(['tutor_id'=>$tutor->id]);$a=Apprentice::factory()->create(['tutor_id'=>$tutor->id,'tutor_group_id'=>$g->id]);$s=TrainingSession::factory()->create(['tutor_group_id'=>$g->id,'session_date'=>today()->subDays($days),'members'=>[$a->id]]);return [$s,$a,$tutor,$g];
    }
    private function mark($s,$a,?string $code='A'): AttendanceMark {return AttendanceMark::create(['training_session_id'=>$s->id,'apprentice_id'=>$a->id,'code'=>$code,'recorded_by'=>$s->created_by,'comment'=>'PRIVATE REGISTER COMMENT','otj_confirmed'=>false,'otj_minutes'=>null]);}
    public function test_ended_missing_codes_recorded_codes_not_ended_and_cancelled_are_separate(): void
    {
        [$missing]=$this->fixture();[$recorded,$a]=$this->fixture();$this->mark($recorded,$a);[$future]=$this->fixture(-1);[$cancelled]=$this->fixture();$cancelled->state='Cancelled';$cancelled->save();$r=$this->actingAs($this->actor())->get('/sessions')->assertOk()->assertDontSee('PRIVATE REGISTER COMMENT');$this->assertSame(['all'=>4,'needs-register'=>1,'recorded'=>1,'not-ended'=>1,'cancelled'=>1,'checks'=>0],$r->viewData('registerCounts'));$this->assertNull(AttendanceMark::first()->otj_minutes);
    }
    public function test_unknown_blank_codes_empty_roster_and_future_marks_are_not_proof_of_completion(): void
    {
        [$s,$a]=$this->fixture();$this->mark($s,$a,'Z');[$blank,$b]=$this->fixture();$this->mark($blank,$b,null);[$future,$f]=$this->fixture(-1);$this->mark($future,$f,'O');[$empty]=$this->fixture();$empty->members=[];$empty->save();$r=$this->actingAs($this->actor())->get('/sessions')->assertOk();$c=$r->viewData('registerCounts');$this->assertSame(3,$c['checks']);$this->assertSame(1,$c['needs-register']);$this->assertSame(0,$c['recorded']);
    }
    public function test_tutor_scope_still_excludes_foreign_groups_and_mixed_saved_rosters(): void
    {
        [$own,$a,$tutor]=$this->fixture();[$foreign,$b]=$this->fixture();$mixed=TrainingSession::factory()->create(['tutor_group_id'=>$own->tutor_group_id,'members'=>[$a->id,$b->id]]);$this->actingAs($tutor);$r=$this->get('/sessions')->assertOk();$this->assertSame(1,$r->viewData('registerCounts')['all']);$this->get('/sessions?group='.$foreign->tutor_group_id)->assertNotFound();$this->get('/sessions/'.$mixed->id)->assertForbidden();$this->get('/sessions/'.$foreign->id)->assertForbidden();$this->get('/sessions/'.$own->id)->assertOk();
    }
    public function test_dashboard_links_keep_group_apprentice_scope_and_are_not_shown_to_learners(): void
    {
        [$s,$a,$tutor,$g]=$this->fixture();$this->fixture();$r=$this->actingAs($tutor)->get('/dashboard?group='.$g->id.'&apprentice='.$a->id)->assertOk();$this->assertSame(1,$r->viewData('registerSummary')['counts']['needs-register']);$r->assertSee(route('attendance.index',['group'=>$g->id,'apprentice'=>$a->id,'view'=>'needs-register']));$g->tutor_id=$this->actor('tutor')->id;$g->save();$r=$this->get('/dashboard?group='.$g->id.'&apprentice='.$a->id)->assertOk();$this->assertNull($r->viewData('registerSummary'));$learner=$this->actor('apprentice');$a->user_id=$learner->id;$a->save();$r=$this->actingAs($learner)->get('/dashboard')->assertOk()->assertDontSee('id="dashboard-registers-title"',false);$this->assertNull($r->viewData('registerSummary'));$this->get('/sessions')->assertForbidden();
    }
    public function test_readiness_panel_is_read_only_and_does_not_confirm_hours_or_change_history(): void
    {
        [$s,$a,$tutor]=$this->fixture();$m=$this->mark($s,$a,'O');$before=[$s->fresh()->getAttributes(),$m->fresh()->getAttributes()];$this->actingAs($tutor)->get('/sessions/'.$s->id)->assertOk()->assertSee('All saved roster entries have recognised codes')->assertSee('not proof of attendance or eligible OTJ');$this->assertSame($before,[$s->fresh()->getAttributes(),$m->fresh()->getAttributes()]);$this->assertFalse($m->fresh()->otj_confirmed);$this->assertDatabaseCount('learning_events',0);
    }
    public function test_source_problems_are_flagged_and_malformed_rosters_do_not_open_broken_registers(): void
    {
        [$s,$a,$tutor]=$this->fixture();$s->members=[$a->id,$a->id,999999];$s->save();$this->actingAs($this->actor());$r=$this->get('/sessions?view=checks')->assertOk();$this->assertSame(1,$r->viewData('registerRows')->total());$row=$r->viewData('registerRows')->first();$this->assertTrue($row['duplicate_roster']);$this->assertSame(1,$row['missing_people']);$this->get('/reports/operational')->assertStatus(422);$s->members=[['invalid']];$s->save();$this->get('/sessions')->assertOk()->assertSee('Malformed saved roster');$this->get('/sessions/'.$s->id)->assertStatus(422);$r=$this->get('/dashboard?group='.$s->tutor_group_id)->assertOk();$this->assertSame(1,$r->viewData('registerSummary')['counts']['checks']);$this->assertNull($r->viewData('attendanceLedger'));$this->get('/reports/operational')->assertStatus(422);$this->actingAs($tutor)->get('/sessions/'.$s->id)->assertForbidden();$s->members=[$a->id];$s->end_time='xx:xx';$s->save();$this->actingAs($this->actor())->get('/sessions?view=checks')->assertOk()->assertSee('Session timing needs checking');$this->get('/sessions/'.$s->id)->assertStatus(422);
    }
    public function test_search_view_and_pagination_follow_scope_and_session_end_boundary(): void
    {
        [$s,$a,$tutor,$g]=$this->fixture(0);$r=$this->actingAs($tutor)->get('/sessions?view=not-ended')->assertOk();$this->assertSame(1,$r->viewData('registerRows')->total());$this->travelTo(today()->setTime(16,0));$r=$this->get('/sessions?view=needs-register')->assertOk();$this->assertSame(1,$r->viewData('registerRows')->total());for($i=0;$i<26;$i++){TrainingSession::factory()->create(['tutor_group_id'=>$g->id,'session_date'=>today()->subDays(2),'name'=>'Searchable session','members'=>[$a->id]]);}$r=$this->get('/sessions?group='.$g->id.'&search=Searchable&view=needs-register&page=99')->assertOk();$this->assertSame(26,$r->viewData('registerCounts')['all']);$this->assertSame(2,$r->viewData('registerRows')->currentPage());$this->assertCount(1,$r->viewData('registerRows')->items());$this->assertStringContainsString('search=Searchable',$r->viewData('registerRows')->previousPageUrl());
    }
    public function test_invalid_inputs_inactive_groups_and_foreign_apprentice_cannot_expand_scope(): void
    {
        [$s,$a,$tutor,$g]=$this->fixture();[$other,$foreign]=$this->fixture();$this->actingAs($tutor)->getJson('/sessions?view=made-up')->assertUnprocessable()->assertJsonValidationErrors('view');$this->get('/sessions?apprentice='.$foreign->id)->assertNotFound();$this->getJson('/sessions?search[]=invalid')->assertUnprocessable()->assertJsonValidationErrors('search');$g->active=false;$g->save();$r=$this->get('/sessions')->assertOk();$this->assertSame(0,$r->viewData('registerCounts')['all']);
    }
}
