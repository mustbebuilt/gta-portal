<?php
namespace Tests\Feature;
use App\Models\{User,Apprentice,TutorGroup,TrainingSession,TimetableRoom as Room,TimetableStaffWindow as Window,TimetablePlan as Plan,TimetablePlanEntry as Entry,TimetableFlexibleDay as Day,TimetableGroupBrief as Brief,TimetableCoverAllocation as Cover,TimetableGovernanceState as State,TimetableGovernanceRole as Role,TimetableAllocationControl as Control,TimetableStaffSection as Section};
use App\Support\{CentrePlanning as P,CentreTimetable as T,TimetableDuties as D,TimetableCover as C,ReviewedImports,TimetableGovernance as G,OccurrenceRegisters as O,TimetableInsights as I,RegisterCreation};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\{DB,Mail,Http};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\AuditFault;
use Tests\TestCase;
class OccurrenceRegistersTest extends TestCase {
 use LazilyRefreshDatabase;
 private function fixture():array {$admin=User::factory()->create(['role'=>'admin']);$planner=User::factory()->create(['role'=>'manager']);P::grant($admin,1,$planner,0,true);$tutor=User::factory()->create(['role'=>'tutor']);$cover=User::factory()->create(['role'=>'tutor']);$other=User::factory()->create(['role'=>'tutor']);$group=TutorGroup::factory()->create(['tutor_id'=>$tutor->id]);$a=Apprentice::factory()->create(['tutor_group_id'=>$group->id,'tutor_id'=>$tutor->id,'start_date'=>'2026-09-07']);$room=Room::where('site','Doncaster')->first();P::room($admin,1,$room,1,['capacity'=>8,'uses'=>['Teaching','Admin','Review'],'active'=>true]);$room->refresh();$date=today()->addDays(7)->startOfWeek()->addDay()->toDateString();$dates=P::dates($date,2);foreach([$tutor,$cover,$other]as$u){P::windows($admin,1,$u,$dates,ReviewedImports::hash(P::windowSource($u,$dates)),true,'08:00','18:00');}$d=['staff_id'=>$tutor->id,'date'=>$date,'weeks'=>2,'start'=>'09:00','end'=>'16:00','kind'=>'Teaching','site'=>'Doncaster','room_id'=>$room->id,'group_id'=>$group->id,'title'=>'Fictional whole-day class','headcount'=>4];return compact('admin','planner','tutor','cover','other','group','a','room','date','dates','d');}
 private function reject(callable $fn):void {try{$fn();$this->fail('Expected checked rejection');}catch(ValidationException $e){$this->assertNotEmpty($e->errors());}}
 private function flex(array $f,?User $who=null):Day {$who??=$f['cover'];D::saveDays($f['planner'],1,$who,$f['planner'],$f['dates'],ReviewedImports::hash(D::preview($who,$f['dates'])),'09:00','16:00');return D::day($who->id,$f['date']);}
 private function plan(array $f,?array $data=null):Plan {$p=P::save($f['planner'],1,null,0,(string)Str::uuid(),$data??$f['d']);P::check($f['planner'],1,$p,1,true);P::publish($f['planner'],1,$p->refresh(),2);return $p->refresh();}
 private function proposal(array $f):Cover {$p=$this->plan($f);$day=$this->flex($f);$e=Entry::where('plan_id',$p->id)->orderBy('work_date')->first();return C::prepare($f['planner'],1,$e,$day,(string)Str::uuid(),'PRIVATE-SUITABILITY-REFERENCE');}
 private function activate(array $f):Cover {$c=$this->proposal($f);C::check($f['planner'],1,$c,1);C::publish($f['planner'],1,$c->refresh(),2);return $c->refresh();}


 private function created(array $f):array {$p=$this->plan($f);$e=Entry::where('plan_id',$p->id)->orderBy('work_date')->first();$s=O::create($f['admin'],1,$e,3,'Fictional checked learning',[['start'=>'12:00','end'=>'13:00']]);return [$p,$e->refresh(),$s];}
 private function delivered(array $f):array {[$p,$e,$s]=$this->created($f);$this->travelTo(\Carbon\Carbon::parse($f['date'].' 17:00'));$s->forceFill(['delivered_learning'=>'Fictional confirmed delivery','version'=>2])->save();\App\Models\AttendanceMark::create(['training_session_id'=>$s->id,'apprentice_id'=>$f['a']->id,'code'=>'O','arrival'=>'09:00','departure'=>'16:00','recorded_by'=>$f['admin']->id]);I::confirm($f['admin'],1,$s,$f['tutor'],0,I::source($s)['hash'],false,'Checked delivery');return [$p,$e->refresh(),$s];}
 private function reconcile(array $f,Plan $p,Entry $e,$s):void {$receipt=\App\Models\TimetableDeliveryRecord::find($s->id);O::reconcile($f['admin'],1,$e,$p->version,ReviewedImports::hash($e->link_proof),$receipt->version,I::source($s)['hash'],'Checked exact occurrence');}
 public function test_explicit_create_uses_one_canonical_register_and_no_marks():void {$f=$this->fixture();[$p,$e,$s]=$this->created($f);$this->assertSame($s->id,$e->training_session_id);$this->assertTrue(P::linkCurrent($e,$p));$this->assertSame([$f['a']->id],$s->members);$this->assertDatabaseCount('training_sessions',1);$this->assertDatabaseCount('attendance_marks',0);$this->assertDatabaseCount('historical_otj_records',0);$this->assertSame(1,DB::table('learning_events')->where('event','session_planned')->count());}
 public function test_replay_returns_same_register_without_extra_audit_or_sessions():void {$f=$this->fixture();[$p,$e,$s]=$this->created($f);$before=DB::table('timetable_planning_events')->count();$same=O::create($f['admin'],1,$e,3,'Fictional checked learning',[['start'=>'12:00','end'=>'13:00']]);$this->assertSame($s->id,$same->id);$this->assertDatabaseCount('training_sessions',1);$this->assertSame($before,DB::table('timetable_planning_events')->count());}
 public function test_different_payload_is_not_silently_treated_as_replay():void {$f=$this->fixture();[$p,$e,$s]=$this->created($f);$this->reject(fn()=>O::create($f['admin'],1,$e,3,'Changed request',[]));$this->assertDatabaseCount('training_sessions',1);}
 public function test_delegated_timetable_role_does_not_grant_register_creation():void {$f=$this->fixture();$p=$this->plan($f);$e=Entry::where('plan_id',$p->id)->first();$this->actingAs($f['planner'])->post('/centre-planning/entries/'.$e->id.'/create-register',['version'=>3,'planned_learning'=>'Some learning','breaks_checked'=>1,'confirmed'=>1])->assertForbidden();$this->assertDatabaseCount('training_sessions',0);}
 public function test_assigned_tutor_needs_both_timetable_and_register_permissions():void {$f=$this->fixture();G::role($f['admin'],1,$f['tutor'],0,State::find(1)->revision,'centre_manager',false,false,'Reviewed grant');$p=$this->plan($f);$e=Entry::where('plan_id',$p->id)->first();$s=O::create($f['tutor'],1,$e,3,'Tutor checked learning',[]);$this->assertSame($f['tutor']->id,$s->created_by);}
 public function test_draft_stale_or_locked_series_cannot_create():void {$f=$this->fixture();$p=P::save($f['admin'],1,null,0,(string)Str::uuid(),$f['d']);$e=Entry::where('plan_id',$p->id)->first();$this->reject(fn()=>O::create($f['admin'],1,$e,1,'Not yet published',[]));P::check($f['admin'],1,$p,1,true);P::publish($f['admin'],1,$p->refresh(),2);$this->reject(fn()=>O::create($f['admin'],1,$e,2,'Stale version',[]));G::allocation($f['admin'],1,$p->refresh(),0,State::find(1)->revision,true,false,'Locked');$this->reject(fn()=>O::create($f['admin'],1,$e,3,'Locked',[]));$this->assertDatabaseCount('training_sessions',0);}
 public function test_existing_overlapping_register_is_not_duplicated():void {$f=$this->fixture();$p=$this->plan($f);$e=Entry::where('plan_id',$p->id)->first();TrainingSession::factory()->create(['tutor_group_id'=>$f['group']->id,'session_date'=>$e->work_date,'start_time'=>'09:00','end_time'=>'16:00','members'=>[$f['a']->id]]);$this->reject(fn()=>O::create($f['admin'],1,$e,3,'Already present',[]));$this->assertDatabaseCount('training_sessions',1);}
 public function test_bad_breaks_and_changed_roster_roll_back_creation():void {$f=$this->fixture();$p=$this->plan($f);$e=Entry::where('plan_id',$p->id)->first();$this->reject(fn()=>O::create($f['admin'],1,$e,3,'Bad breaks',[['start'=>'08:00','end'=>'10:00']]));Apprentice::factory()->create(['tutor_group_id'=>$f['group']->id]);$this->reject(fn()=>O::create($f['admin'],1,$e,3,'Changed roster',[]));$this->assertDatabaseCount('training_sessions',0);}
 public function test_creation_audit_failure_rolls_back_session_link_and_learning_events():void {$f=$this->fixture();$p=$this->plan($f);$e=Entry::where('plan_id',$p->id)->first();AuditFault::install('create_link_fault','timetable_planning_events','Fictional failure','register_created_from_plan');try{O::create($f['admin'],1,$e,3,'Checked',[]);$this->fail('Expected fault');}catch(\Illuminate\Database\QueryException){$this->assertDatabaseCount('training_sessions',0);$this->assertDatabaseCount('learning_events',0);$this->assertNull($e->refresh()->training_session_id);}}
 public function test_marked_register_requires_explicit_delivery_reconciliation_before_collapse():void {$f=$this->fixture();[$p,$e,$s]=$this->delivered($f);$this->assertFalse(P::linkCurrent($e,$p));$this->assertCount(2,P::busy([$f['date']])['rows']);$before=$s->fresh()->getAttributes();$this->reconcile($f,$p,$e,$s);$this->assertTrue(P::linkCurrent($e->refresh(),$p));$this->assertCount(1,P::busy([$f['date']])['rows']);$this->assertSame($before,$s->fresh()->getAttributes());}
 public function test_later_receipt_correction_or_mark_change_reopens_link_hold():void {$f=$this->fixture();[$p,$e,$s]=$this->delivered($f);$this->reconcile($f,$p,$e,$s);I::confirm($f['admin'],1,$s,$f['cover'],1,I::source($s)['hash'],true,'Corrected actual teacher');$this->assertFalse(P::linkCurrent($e->refresh(),$p));$this->reconcile($f,$p,$e,$s);$this->assertTrue(P::linkCurrent($e->refresh(),$p));$s->marks()->update(['departure'=>'15:00']);$this->assertFalse(P::linkCurrent($e->refresh(),$p));}
 public function test_group_tutor_transfer_does_not_reassign_reconciled_historical_register():void {$f=$this->fixture();[$p,$e,$s]=$this->delivered($f);$this->reconcile($f,$p,$e,$s);$f['group']->forceFill(['tutor_id'=>$f['other']->id])->save();$this->assertTrue(P::linkCurrent($e->refresh(),$p));$this->assertCount(1,P::busy([$f['date']])['rows']);}
 public function test_changed_date_time_or_roster_cannot_be_reconciled():void {$f=$this->fixture();[$p,$e,$s]=$this->delivered($f);$s->forceFill(['end_time'=>'17:00'])->save();I::confirm($f['admin'],1,$s,$f['tutor'],1,I::source($s)['hash'],false,'Checked correction');$this->reject(fn()=>$this->reconcile($f,$p,$e,$s));$this->assertFalse(P::linkCurrent($e,$p));}
 public function test_stale_link_hash_or_delivery_version_rejected():void {$f=$this->fixture();[$p,$e,$s]=$this->delivered($f);$this->reject(fn()=>O::reconcile($f['admin'],1,$e,3,str_repeat('0',64),1,I::source($s)['hash'],'Stale'));$this->reject(fn()=>O::reconcile($f['admin'],1,$e,3,ReviewedImports::hash($e->link_proof),99,I::source($s)['hash'],'Stale'));}
 public function test_reconciliation_event_failure_keeps_original_proof():void {$f=$this->fixture();[$p,$e,$s]=$this->delivered($f);$before=$e->link_proof;AuditFault::install('reconcile_fault','timetable_planning_events','Fictional failure','delivered_link_reconciled');try{$this->reconcile($f,$p,$e,$s);$this->fail('Expected fault');}catch(\Illuminate\Database\QueryException){$this->assertSame($before,$e->fresh()->link_proof);}}
 public function test_new_forms_render_and_require_confirmation():void {$f=$this->fixture();$p=$this->plan($f);$e=Entry::where('plan_id',$p->id)->first();$this->actingAs($f['admin'])->get('/centre-planning/plans/'.$p->id)->assertOk()->assertSee('Create and link register');$this->post('/centre-planning/entries/'.$e->id.'/create-register',['version'=>3,'planned_learning'=>'Checked','breaks_checked'=>1])->assertSessionHasErrors('confirmed');}

 public function test_assigned_tutor_opens_existing_register_from_own_timetable(): void
 {
     $f = $this->fixture();
     [$plan, $entry, $register] = $this->created($f);
     $url = route('attendance.show', $register);
     $board = route('centre-planning.index', ['week' => $f['date']]);
     $this->actingAs($f['tutor'])->get($board)->assertOk()
         ->assertSee('Open matching register')->assertSee($url);
     $this->get($url)->assertOk();
     $this->actingAs($f['other'])->get($board)->assertOk()->assertDontSee($url);
     $this->get($url)->assertForbidden();
     $this->actingAs($f['tutor'])->get($board.'&scope=staff')->assertOk()->assertDontSee($url);
     $this->actingAs($f['admin'])->get(route('centre-planning.show', $plan))
         ->assertOk()->assertSee($url)->assertDontSee(route('occurrence-register.create', $entry));
     $this->assertDatabaseCount('training_sessions', 1);
     $this->assertDatabaseCount('attendance_marks', 0);
 }

 public function test_multiple_overlaps_or_changed_roster_need_review(): void
 {
     $f = $this->fixture();
     [$plan, $entry, $register] = $this->created($f);
     $other = TrainingSession::factory()->create([
         'tutor_group_id' => $f['group']->id, 'session_date' => $f['date'],
         'start_time' => '10:00', 'end_time' => '14:00', 'members' => [$f['a']->id],
     ]);
     $board = route('centre-planning.index', ['week' => $f['date']]);
     $series = route('centre-planning.show', $plan);
     $this->actingAs($f['admin'])->get($series)->assertOk()
         ->assertSee('Review register')->assertDontSee(route('occurrence-register.create', $entry));
     $this->get($board)->assertOk()->assertDontSee('Open matching register');
     $other->delete();
     Apprentice::factory()->create(['tutor_group_id' => $f['group']->id, 'tutor_id' => $f['tutor']->id]);
     $this->get($series)->assertOk()->assertSee('Review register')->assertDontSee('Open matching register');
     $this->get($board)->assertOk()->assertDontSee('Open matching register');
     $this->assertDatabaseCount('training_sessions', 1);
 }

 public function test_weekly_schedule_prefills_timetable_create_and_both_menus_open_the_same_register(): void
 {
     $f = $this->fixture();
     $f['group']->forceFill(['weekly_register' => ['revisions' => [[
         'first_day' => $f['date'], 'start' => '09:00', 'end' => '16:00',
         'planned_learning' => 'Fictional weekly learning',
         'breaks' => [['start' => '12:00', 'end' => '13:00']],
     ]]]])->save();
     $plan = $this->plan($f);
     $entry = Entry::where('plan_id', $plan->id)->orderBy('work_date')->firstOrFail();
     $series = route('centre-planning.show', $plan);
     $create = route('occurrence-register.create', $entry);
     $this->actingAs($f['admin'])->get($series)->assertOk()
         ->assertSee($create)->assertSee('Fictional weekly learning')->assertSee('value="12:00"', false);
     $data = [
         'version' => $plan->version, 'confirmed' => 1, 'planned_learning' => 'Fictional weekly learning',
         'breaks' => [['start' => '12:00', 'end' => '13:00']],
     ];
     $this->from($series)->post($create, $data)->assertSessionHasErrors('breaks_checked');
     $this->assertDatabaseCount('training_sessions', 0);
     $response = $this->post($create, $data + ['breaks_checked' => 1]);
     $register = TrainingSession::sole();
     $response->assertRedirect(route('attendance.show', $register));
     $this->assertSame($data['breaks'], $register->breaks);
     $this->assertNotNull($register->regular_week);
     $this->actingAs($f['tutor'])->post(route('attendance.weekly.open', $f['group']), [
         'date' => $f['date'], 'week' => $register->regular_week,
     ])->assertRedirect(route('attendance.show', $register));
     $this->assertDatabaseCount('training_sessions', 1);
     $this->assertDatabaseCount('attendance_marks', 0);
 }

 public function test_opening_unlinked_exact_register_requires_no_planning_link_or_data_changes(): void
 {
     $f = $this->fixture();
     $plan = $this->plan($f);
     $entry = Entry::where('plan_id', $plan->id)->orderBy('work_date')->firstOrFail();
     $register = TrainingSession::factory()->create([
         'tutor_group_id' => $f['group']->id, 'session_date' => $f['date'],
         'start_time' => '09:00', 'end_time' => '16:00', 'members' => [$f['a']->id],
     ]);
     $this->actingAs($f['admin'])->get(route('centre-planning.show', $plan))->assertOk()
         ->assertSee('Open matching register')->assertSee(route('attendance.show', $register))
         ->assertDontSee(route('occurrence-register.create', $entry));
     $this->assertNull($entry->fresh()->training_session_id);
     $this->assertDatabaseCount('training_sessions', 1);
     $this->assertDatabaseCount('attendance_marks', 0);
 }

 public function test_both_workspaces_open_one_weekly_register_after_scheduled_day(): void
 {
     $f = $this->fixture();
     $date = \Carbon\CarbonImmutable::parse($f['date']);
     $f['group']->forceFill(['weekly_register' => ['revisions' => [[
         'first_day' => $f['date'], 'start' => '09:00', 'end' => '16:00',
         'planned_learning' => 'Fictional weekly learning', 'breaks' => [],
     ]]]])->save();
     $this->plan($f);
     $this->travelTo($date->addDay()->setTime(8, 0));
     $open = route('attendance.weekly.open', $f['group']);
     $board = route('centre-planning.index');
     $this->actingAs($f['tutor'])->get($board)->assertOk()->assertSee($open)->assertSee("Open this week’s register");
     $this->get(route('attendance.index'))->assertOk()->assertSee($open)->assertSee("Open this week’s register");
     $this->assertDatabaseCount('training_sessions', 0);
     $params = ['date' => $f['date'], 'week' => $date->startOfWeek()->toDateString()];
     $this->post($open, $params)->assertRedirect();
     $session = TrainingSession::sole();
     $this->post($open, $params)->assertRedirect(route('attendance.show', $session));
     $this->get($board)->assertOk()->assertSee(route('attendance.show', $session));
     $this->assertDatabaseCount('training_sessions', 1);
     $this->assertDatabaseCount('attendance_marks', 0);
 }
}
