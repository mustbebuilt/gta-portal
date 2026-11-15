<?php
namespace Tests\Feature;

use App\Models\{User,Apprentice,TutorGroup,TrainingSession,AttendanceMark,LearningTask,TimetableDeliveryRecord};
use App\Support\{CentrePlanning as P,CentreTimetable as T,TimetableGovernance as M,TimetableInsights as I};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\Support\AuditFault;

class TimetableManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(): array
    {
        $admin=User::factory()->create(['role'=>'admin']);
        $ceo=User::factory()->create(['role'=>'ceo']);
        $manager=User::factory()->create(['role'=>'manager']);
        $tutor=User::factory()->create(['role'=>'tutor']);
        $staff=User::factory()->create(['role'=>'staff']);
        $group=TutorGroup::factory()->create(['tutor_id'=>$tutor->id]);
        $a=Apprentice::factory()->create(['tutor_id'=>$tutor->id,'tutor_group_id'=>$group->id]);
        $s=TrainingSession::factory()->create(['tutor_group_id'=>$group->id,'created_by'=>$admin->id,
            'session_date'=>today()->subDay()->toDateString(),'start_time'=>'09:00','end_time'=>'16:00',
            'breaks'=>[['start'=>'12:00','end'=>'13:00']],'members'=>[$a->id],
            'delivered_learning'=>'Checked practical session delivered','state'=>'Planned']);
        AttendanceMark::create(['training_session_id'=>$s->id,'apprentice_id'=>$a->id,'code'=>'O','arrival'=>'09:00','departure'=>'16:00','recorded_by'=>$admin->id]);
        return compact('admin','ceo','manager','tutor','staff','group','a','s');
    }
    private function confirm(array $f,?User $teacher=null,int $version=0,bool $cover=false): void
    {
        I::confirm($f['admin'],1,$f['s'],$teacher??$f['tutor'],$version,I::source($f['s'])['hash'],$cover,'Checked actual delivery reference');
    }
    private function report(array $f): array
    {
        return I::report($f['admin'],today()->subDays(3)->toDateString(),today()->toDateString());
    }
    public function test_no_delivery_is_inferred_from_complete_register(): void
    {
        $f=$this->fixture();$r=$this->report($f);$this->assertSame(1,$r['counts']['recorded']);
        $this->assertSame(1,$r['counts']['unattributed']);$this->assertSame(0,$r['counts']['confirmed']);
    }
    public function test_confirmed_cover_credits_actual_tutor_not_current_group_owner_or_recorder(): void
    {
        $f=$this->fixture();$cover=User::factory()->create(['role'=>'tutor']);$before=$f['s']->fresh()->getAttributes();
        $this->confirm($f,$cover,0,true);$r=$this->report($f);
        $row=collect($r['rows'])->firstWhere('id',$cover->id);
        $this->assertSame(1,$row['delivery_sessions']);$this->assertCount(1,$row['cover_dates']);
        $this->assertSame(360,$row['delivered_minutes']);$this->assertSame(1,$row['unique_apprentices']);
        $this->assertEquals(100,$row['attendance']['percent']);
        $this->assertSame($before,$f['s']->fresh()->getAttributes());
        $this->assertSame($f['tutor']->id,$f['group']->fresh()->tutor_id);
    }
    public function test_register_change_invalidates_confirmation_without_deleting_history(): void
    {
        $f=$this->fixture();$this->confirm($f);$f['s']->increment('version');
        $this->assertSame(0,$this->report($f)['counts']['confirmed']);
        $this->assertDatabaseCount('timetable_delivery_records',1);
    }
    public function test_all_absent_or_incomplete_register_cannot_be_confirmed(): void
    {
        $f=$this->fixture();$f['s']->marks()->update(['code'=>'A']);
        $this->expectException(ValidationException::class);$this->confirm($f);
    }
    public function test_duplicate_dates_are_not_counted_as_extra_days_or_apprentices(): void
    {
        $f=$this->fixture();$this->confirm($f);
        $s2=$f['s']->replicate();$s2->start_time='16:00';$s2->end_time='17:00';$s2->breaks=[];$s2->save();
        AttendanceMark::create(['training_session_id'=>$s2->id,'apprentice_id'=>$f['a']->id,'code'=>'O','arrival'=>'16:00','departure'=>'17:00','recorded_by'=>$f['admin']->id]);
        $g=$f;$g['s']=$s2;$this->confirm($g);$row=collect($this->report($f)['rows'])->firstWhere('id',$f['tutor']->id);
        $this->assertSame(2,$row['delivery_sessions']);$this->assertCount(1,$row['delivery_dates']);
        $this->assertSame(1,$row['unique_apprentices']);$this->assertSame(420,$row['delivered_minutes']);
    }
    public function test_current_marking_is_separate_from_interval_delivery(): void
    {
        $f=$this->fixture();LearningTask::factory()->create(['apprentice_id'=>$f['a']->id,'status'=>'Awaiting marking','submitted_at'=>now()->subDays(9)]);
        $row=collect($this->report($f)['rows'])->firstWhere('id',$f['tutor']->id);
        $this->assertSame(1,$row['current_tasks']['overdue_marking']);$this->assertSame(0,$row['delivery_sessions']);
    }
    public function test_stale_confirmation_version_rejected_and_details_encrypted(): void
    {
        $f=$this->fixture();$this->confirm($f);
        $this->assertStringNotContainsString('Checked actual delivery',DB::table('timetable_delivery_records')->value('details'));
        $this->expectException(ValidationException::class);$this->confirm($f);
    }
    public function test_audit_failure_rolls_back_confirmation(): void
    {
        $f=$this->fixture();AuditFault::install('delivery_fault','timetable_duty_events','Fictional failure','delivery_confirmed');
        try{$this->confirm($f);$this->fail('Expected audit failure');}catch(\Illuminate\Database\QueryException){$this->assertDatabaseCount('timetable_delivery_records',0);}
    }
    public function test_management_views_render_and_use_private_headers(): void
    {
        $f=$this->fixture();$this->actingAs($f['admin']);
        $this->get('/centre-planning/governance')->assertOk()->assertHeader('Cache-Control','no-store, private');
        $this->get('/centre-planning/management?from='.today()->subDays(3)->toDateString().'&to='.today()->toDateString())
            ->assertOk()->assertSee('Current apprentice progress')->assertSee('actual tutor attribution');
    }
    private function reject(callable $fn):void {try{$fn();$this->fail('Expected checked rejection');}catch(ValidationException $e){$this->assertNotEmpty($e->errors());}}
    private function row(array $f):array {return collect($this->report($f)['rows'])->firstWhere('id',$f['tutor']->id);}
    public function test_code_only_legacy_register_has_no_confirmable_actual_minutes():void {$f=$this->fixture();$f['s']->marks()->update(['arrival'=>null,'departure'=>null]);$this->reject(fn()=>$this->confirm($f));$this->assertFalse($this->report($f)['registers'][0]['can_confirm']);$this->assertDatabaseCount('timetable_delivery_records',0);}
    public function test_late_arrival_and_early_departure_limit_confirmed_minutes():void {$f=$this->fixture();$f['s']->marks()->update(['arrival'=>'10:00','departure'=>'15:00']);$this->confirm($f);$this->assertSame(240,$this->row($f)['delivered_minutes']);}
    public function test_overlapping_attended_intervals_union_instead_of_summing_learners():void {$f=$this->fixture();$b=Apprentice::factory()->create(['tutor_group_id'=>$f['group']->id]);$f['s']->forceFill(['members'=>[$f['a']->id,$b->id]])->save();AttendanceMark::create(['training_session_id'=>$f['s']->id,'apprentice_id'=>$b->id,'code'=>'T','arrival'=>'10:00','departure'=>'15:00','recorded_by'=>$f['admin']->id]);$this->confirm($f);$this->assertSame(360,$this->row($f)['delivered_minutes']);$this->assertSame(2,$this->row($f)['unique_apprentices']);}
    public function test_two_overlapping_sessions_on_one_date_do_not_double_teaching_minutes():void {$f=$this->fixture();$this->confirm($f);$other=$f['s']->replicate();$other->save();AttendanceMark::create(['training_session_id'=>$other->id,'apprentice_id'=>$f['a']->id,'code'=>'O','arrival'=>'09:00','departure'=>'16:00','recorded_by'=>$f['admin']->id]);$g=$f;$g['s']=$other;$this->confirm($g);$x=$this->row($f);$this->assertSame(360,$x['delivered_minutes']);$this->assertCount(1,$x['delivery_dates']);$this->assertSame(2,$x['delivery_sessions']);}
    #[\PHPUnit\Framework\Attributes\TestWith(['A'])] #[\PHPUnit\Framework\Attributes\TestWith(['B'])] #[\PHPUnit\Framework\Attributes\TestWith(['C'])] #[\PHPUnit\Framework\Attributes\TestWith(['E'])]
    public function test_complete_non_attended_codes_never_establish_delivery(string $code):void {$f=$this->fixture();$f['s']->marks()->update(['code'=>$code]);$this->reject(fn()=>$this->confirm($f));$this->assertSame(0,$this->report($f)['counts']['confirmed']);}
    public function test_unmarked_saved_roster_holds_confirmation_and_percentage():void {$f=$this->fixture();$b=Apprentice::factory()->create(['tutor_group_id'=>$f['group']->id]);$f['s']->forceFill(['members'=>[$f['a']->id,$b->id]])->save();$this->reject(fn()=>$this->confirm($f));$this->assertSame(1,$this->report($f)['counts']['needs-register']);$this->assertNull($this->row($f)['attendance']['percent']);}
    #[\PHPUnit\Framework\Attributes\TestWith(['Cancelled'])] #[\PHPUnit\Framework\Attributes\TestWith(['Unexpected'])]
    public function test_cancelled_or_unknown_state_is_not_delivered(string $state):void {$f=$this->fixture();$f['s']->forceFill(['state'=>$state])->save();$this->reject(fn()=>$this->confirm($f));$this->assertSame(0,$this->report($f)['counts']['confirmed']);}
    public function test_future_session_is_neither_confirmable_nor_delivered():void {$f=$this->fixture();$f['s']->forceFill(['session_date'=>today()->addDays(2)])->save();$this->reject(fn()=>$this->confirm($f));$this->assertDatabaseCount('timetable_delivery_records',0);}
    public function test_null_break_record_or_breaks_covering_all_teaching_hold_minutes():void {$f=$this->fixture();foreach([null,[['start'=>'09:00','end'=>'16:00']]]as$breaks){if($breaks===null){DB::table('training_sessions')->where('id',$f['s']->id)->update(['breaks'=>'null']);}else{$f['s']->forceFill(['breaks'=>$breaks])->save();}$this->reject(fn()=>$this->confirm($f));}$this->assertDatabaseCount('timetable_delivery_records',0);}
    public function test_explicit_empty_break_array_is_valid_not_an_assumed_break():void {$f=$this->fixture();$f['s']->forceFill(['breaks'=>[]])->save();$this->confirm($f);$this->assertSame(420,$this->row($f)['delivered_minutes']);}
    public function test_attendance_denominator_reuses_codes_and_exclusions():void {$f=$this->fixture();$ids=[$f['a']->id];foreach(['A','C']as$code){$a=Apprentice::factory()->create(['tutor_group_id'=>$f['group']->id]);$ids[]=$a->id;AttendanceMark::create(['training_session_id'=>$f['s']->id,'apprentice_id'=>$a->id,'code'=>$code,'recorded_by'=>$f['admin']->id]);}$f['s']->forceFill(['members'=>$ids])->save();$this->confirm($f);$x=$this->row($f);$this->assertEquals(50,$x['attendance']['percent']);$this->assertSame(2,$x['attendance']['expected']);$this->assertSame(1,$x['attendance']['excluded']);$this->assertSame(3,$x['unique_apprentices']);}
    public function test_group_tutor_transfer_does_not_reattribute_or_invalidate_actual_delivery():void {$f=$this->fixture();$this->confirm($f);$other=User::factory()->create(['role'=>'tutor']);$f['group']->forceFill(['tutor_id'=>$other->id])->save();$this->assertSame(1,$this->row($f)['delivery_sessions']);$this->assertSame(0,collect($this->report($f)['rows'])->firstWhere('id',$other->id)['delivery_sessions']);}
    public function test_programme_change_holds_receipt_for_human_review():void {$f=$this->fixture();$this->confirm($f);$programme=\App\Models\Programme::factory()->create();$f['group']->forceFill(['programme_id'=>$programme->id])->save();$this->assertSame(0,$this->report($f)['counts']['confirmed']);$this->assertSame(1,$this->report($f)['counts']['unattributed']);}
    public function test_historic_inactive_tutor_can_be_explicitly_attributed_without_restoring_login():void {$f=$this->fixture();$f['tutor']->forceFill(['active'=>false])->save();$this->confirm($f);$this->assertSame(1,$this->row($f)['delivery_sessions']);$this->assertFalse($f['tutor']->fresh()->active);}
    public function test_explicit_correction_retains_prior_actor_and_teacher_in_audit():void {$f=$this->fixture();$this->confirm($f);$cover=User::factory()->create(['role'=>'tutor']);$this->confirm($f,$cover,1,true);$this->assertSame(2,TimetableDeliveryRecord::find($f['s']->id)->version);$this->assertSame(2,DB::table('timetable_duty_events')->where('event','delivery_confirmed')->count());$this->assertSame(0,$this->row($f)['delivery_sessions']);}
    public function test_forged_staff_pointer_or_hash_never_credits_delivery():void {$f=$this->fixture();$this->confirm($f);DB::table('timetable_delivery_records')->update(['staff_id'=>$f['admin']->id]);$this->assertSame(0,$this->report($f)['counts']['confirmed']);$this->assertSame(1,$this->report($f)['counts']['unattributed']);}
    public function test_stale_source_hash_rejects_even_when_receipt_version_is_current():void {$f=$this->fixture();$hash=I::source($f['s'])['hash'];$f['s']->marks()->update(['departure'=>'15:00']);$this->reject(fn()=>I::confirm($f['admin'],1,$f['s'],$f['tutor'],0,$hash,false,'Old form'));$this->assertDatabaseCount('timetable_delivery_records',0);}
    public function test_ceo_pause_prevents_actual_attribution_but_retains_read_report():void {$f=$this->fixture();M::pause($f['ceo'],1,\App\Models\TimetableGovernanceState::find(1)->revision,true,'Reviewed pause');$this->actingAs($f['admin'])->get('/centre-planning/management')->assertOk();$this->post('/centre-planning/management/'.$f['s']->id.'/confirm',['version'=>0,'staff_id'=>$f['tutor']->id,'source_hash'=>I::source($f['s'])['hash'],'cover'=>0,'reason'=>'No','confirmed'=>1])->assertForbidden();$this->assertDatabaseCount('timetable_delivery_records',0);}
    public function test_viewer_and_nonstaff_cannot_read_or_mutate_management():void {$f=$this->fixture();foreach(['tutor','employer','apprentice','unassigned']as$role){$u=User::factory()->create(['role'=>$role]);$this->actingAs($u)->get('/centre-planning/management')->assertForbidden();$this->post('/centre-planning/management/'.$f['s']->id.'/confirm',[])->assertForbidden();}}
    public function test_delegation_revocation_is_enforced_on_management_and_confirmation():void {$f=$this->fixture();$rev=fn()=>\App\Models\TimetableGovernanceState::find(1)->revision;M::role($f['admin'],1,$f['manager'],0,$rev(),'centre_manager',false,false,'Reviewed grant');$this->actingAs($f['manager'])->get('/centre-planning/management')->assertOk();M::role($f['admin'],1,$f['manager'],1,$rev(),'viewer',false,false,'Reviewed revoke');$this->get('/centre-planning/management')->assertForbidden();$this->post('/centre-planning/management/'.$f['s']->id.'/confirm',[])->assertForbidden();}
    #[\PHPUnit\Framework\Attributes\TestWith(['2026-02-31','2026-03-01'])] #[\PHPUnit\Framework\Attributes\TestWith(['2026-01-01','2026-06-01'])] #[\PHPUnit\Framework\Attributes\TestWith(['2026-02-01','2026-01-01'])]
    public function test_invalid_or_overlong_report_range_rejected(string $from,string $to):void {$f=$this->fixture();$response=$this->actingAs($f['admin'])->get('/centre-planning/management?from='.$from.'&to='.$to);$this->assertContains($response->status(),[302,422]);}
    public function test_privacy_and_actual_register_bytes_are_unchanged_by_confirmation():void {\Illuminate\Support\Facades\Mail::fake();\Illuminate\Support\Facades\Http::preventStrayRequests();$f=$this->fixture();$before=$f['s']->fresh()->getAttributes();$marks=$f['s']->marks()->get()->toArray();$this->confirm($f);$this->assertSame($before,$f['s']->fresh()->getAttributes());$this->assertSame($marks,$f['s']->marks()->get()->toArray());foreach(['historical_otj_records','signed_review_records','timetable_governance_roles']as$t){$this->assertDatabaseCount($t,0);}\Illuminate\Support\Facades\Mail::assertNothingSent();\Illuminate\Support\Facades\Http::assertNothingSent();}

}
