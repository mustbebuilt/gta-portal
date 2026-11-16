<?php
namespace Tests\Feature;
use App\Models\{User,Apprentice,Employer,Programme,ReviewAppointment as P,ReviewAppointmentParty,ReviewAppointmentResponse};
use App\Support\{ReviewAppointments as A,ReviewMeetingFollowup as F,EmployerActions,SignedReviews};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\{DB,Mail,Http};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\AuditFault;
use Tests\TestCase;
use App\Support\{ReviewNextStep,JourneyOverview};
class ReviewNextStep57Test extends TestCase {
 use LazilyRefreshDatabase;
 private function fixture():array {$admin=User::factory()->create(['role'=>'admin']);$tutor=User::factory()->create(['role'=>'tutor']);$learner=User::factory()->create(['role'=>'apprentice']);$employer=User::factory()->create(['role'=>'employer']);$e=Employer::factory()->create();$a=Apprentice::factory()->create(['user_id'=>$learner->id,'email'=>$learner->email,'employer_id'=>$e->id,'tutor_id'=>$tutor->id,'mentor_id'=>null,'programme_id'=>Programme::factory()->create()->id]);$grant=EmployerActions::grant($admin,$admin->auth_version,$a,['user_id'=>$employer->id,'version'=>0,'identity_reference'=>'Fictional individual verified']);$d=['grant_id'=>$grant->id,'host_id'=>$tutor->id,'date'=>today()->addDays(4)->toDateString(),'start'=>'10:00','end'=>'11:00','mode'=>'Training centre','place'=>'Fictional room 2'];return compact('admin','tutor','learner','employer','e','a','grant','d');}
 private function draft(array $f,?array $d=null):P {return A::save($f['admin'],$f['admin']->auth_version,$f['a'],null,0,(string)Str::uuid(),$d??$f['d']);}
 private function published(array $f):P {$p=$this->draft($f);A::publish($f['admin'],$f['admin']->auth_version,$p,$p->version);return $p->refresh();}
 private function reject(callable $fn):void {try{$fn();$this->fail('Expected checked rejection');}catch(ValidationException $e){$this->assertNotEmpty($e->errors());}}
 private function login(User $u):static {$this->actingAs($u)->withSession(['auth_version'=>$u->auth_version]);return $this;}
 private function data(array $f,string $kind='Not held'):array {return ['kind'=>$kind,'participation'=>array_fill_keys(array_keys(A::ROLES),$kind==='Held'?'Reported present':'Not checked'),'note'=>'PRIVATE-OPERATIONAL-NOTE','owner_id'=>$f['tutor']->id,'due_on'=>today()->addDay()->toDateString(),'action'=>'Arrange another meeting'];}
 private function ended(P $p):void {$this->travelTo($p->ends_at->copy()->addMinute());}

 public function test_home_and_journey_share_the_same_current_booking_and_due_reference():void {$f=$this->fixture();$p=$this->published($f);$before=DB::table('review_appointment_events')->count();$review=ReviewNextStep::for($f['learner'],$f['a']);$journey=JourneyOverview::for($f['learner'],$f['a']);$this->assertSame($p->id,$review['appointment']->id);$this->assertSame($review['cycle'],$journey['nextStep']['review']);$this->login($f['learner'])->get(route('experience.home'))->assertOk()->assertSee('Booked review appointment')->assertSee(route('review-appointments.show',$p),false)->assertSee('reference needs checking')->assertDontSee('Fictional room 2')->assertHeader('Referrer-Policy','no-referrer');$this->assertSame($before,DB::table('review_appointment_events')->count());}
 public function test_home_does_not_present_draft_as_a_booking():void {$f=$this->fixture();$this->draft($f);$this->login($f['learner'])->get(route('experience.home'))->assertOk()->assertDontSee('Booked review appointment')->assertSee('No current published review appointment');}
 public function test_cancelled_booking_disappears_from_home_and_journey():void {$f=$this->fixture();$p=$this->published($f);A::cancel($f['admin'],1,$p,2,'Private cancellation57');$this->assertNull(ReviewNextStep::for($f['learner'],$f['a'])['appointment']);$this->login($f['learner'])->get(route('experience.home'))->assertOk()->assertDontSee('Booked review appointment')->assertDontSee('Private cancellation57');}
 public function test_changed_authority_is_not_a_current_home_booking():void {$f=$this->fixture();$this->published($f);$f['a']->update(['tutor_id'=>User::factory()->create(['role'=>'tutor'])->id]);$this->assertNull(ReviewNextStep::for($f['learner'],$f['a'])['appointment']);$this->login($f['learner'])->get(route('experience.home'))->assertOk()->assertDontSee('Booked review appointment');}
 public function test_unrelated_apprentice_cannot_read_another_review_summary():void {$f=$this->fixture();$this->published($f);$u=User::factory()->create(['role'=>'apprentice']);try{ReviewNextStep::for($u,$f['a']);$this->fail('Expected403');}catch(\Symfony\Component\HttpKernel\Exception\HttpException$e){$this->assertSame(403,$e->getStatusCode());}}
 public function test_missing_profile_keeps_the_existing_linking_hold():void {$u=User::factory()->create(['role'=>'apprentice']);$this->login($u)->get(route('experience.home'))->assertStatus(409)->assertSee('Your profile needs linking')->assertDontSee('What is left before Gateway?');}
 public function test_booking_does_not_advance_journey_or_infer_gateway_completion():void {$f=$this->fixture();$this->published($f);$journey=JourneyOverview::for($f['learner'],$f['a']);$this->assertSame([],$journey['events']);$this->assertFalse($journey['gatewaySummary']['all_met']);$this->assertFalse($journey['gatewaySummary']['current']);}
}
