<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\TrainingSession;
use App\Models\TutorGroup;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class WeeklyRegisterTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_weekly_schedule_reuses_one_register_supports_closure_and_requires_complete_submission(): void
    {
        $this->travelTo(Carbon::parse('2026-11-24 08:00'));
        $manager = User::factory()->create(['role'=>'admin','active'=>true]);
        $tutor = User::factory()->create(['role'=>'tutor','active'=>true]);
        $other = User::factory()->create(['role'=>'tutor','active'=>true]);
        $group = TutorGroup::factory()->create(['tutor_id'=>$tutor->id]);
        $learner = Apprentice::factory()->create(['tutor_id'=>$tutor->id,'tutor_group_id'=>$group->id]);
        $base = '/register-groups/'.$group->id;
        $settings = ['record_version'=>1,'first_day'=>'2026-11-24','start'=>'09:00','end'=>'16:00',
            'breaks_checked'=>1,'break_start'=>'12:00','break_end'=>'13:00','planned_learning'=>'Practical workshop'];
        $this->actingAs($tutor)->post($base.'/schedule',$settings)->assertForbidden();
        $this->actingAs($manager)->post($base.'/schedule',$settings)->assertSessionHasNoErrors();
        $this->actingAs($tutor)->get(route('attendance.index'))->assertOk()
            ->assertSeeInOrder(['Your next register','Open weekly register','Weekly groups and schedules'])
            ->assertSee(route('attendance.weekly.open', $group));
        $this->assertDatabaseCount('training_sessions', 0);
        $this->post($base.'/open',['date'=>'2026-11-24','week'=>'2026-11-23'])->assertRedirect();
        $this->artisan('gta:weekly-registers')->assertSuccessful();
        $this->artisan('gta:weekly-registers')->assertSuccessful();
        $first = TrainingSession::sole();
        $open = ['date'=>'2026-11-24','week'=>'2026-11-23'];
        $this->actingAs($other)->post($base.'/open',$open)->assertForbidden();
        $this->actingAs($tutor)->get('/sessions?group='.$group->id)->assertOk()->assertSee('Apprentice Register')->assertSee('Open register');
        $this->post($base.'/open',$open)->assertRedirect('/sessions/'.$first->id);
        $this->assertDatabaseCount('training_sessions', 1);
        $this->assertSame([$learner->id], $first->members);
        $this->travelTo(Carbon::parse('2026-11-24 17:00'));
        $payload = ['version'=>1,'delivered_learning'=>'Vehicle inspection','marks'=>[$learner->id=>['code'=>'']]];
        $this->post('/sessions/'.$first->id,$payload)->assertSessionHasErrors('marks.'.$learner->id.'.code');
        $payload['marks'][$learner->id] = ['code'=>'O','arrival'=>'09:00','departure'=>'16:00','otj_confirmed'=>1];
        $this->post('/sessions/'.$first->id,$payload)->assertSessionHasErrors('weekly_confirmed');
        $this->post('/sessions/'.$first->id,$payload + ['weekly_confirmed'=>1])->assertSessionHasNoErrors();
        $this->assertSame(360,$first->marks()->sole()->otj_minutes);
        $this->get('/sessions?group='.$group->id)->assertOk()->assertSee('Submitted');

        $this->travelTo(Carbon::parse('2026-12-01 08:00'));
        $this->artisan('gta:weekly-registers')->assertSuccessful();
        $this->assertDatabaseCount('training_sessions', 2);
        $this->travelTo(Carbon::parse('2026-12-02 10:00'));
        $this->get('/sessions?group='.$group->id.'&view=needs-register')->assertOk()->assertSee('01 Dec 2026');
        $this->actingAs($manager)->post($base.'/close',['record_version'=>2,'closed_on'=>'2026-12-02','reason'=>'Group training has ended.'])->assertSessionHasNoErrors();
        $this->travelTo(Carbon::parse('2026-12-08 08:00'));
        $this->artisan('gta:weekly-registers')->assertSuccessful();
        $this->assertDatabaseCount('training_sessions', 2);
        $this->get('/sessions?group='.$group->id.'&view=needs-register')->assertOk()->assertSee('Weekly schedule closed')->assertSee('01 Dec 2026');
    }
    public function test_a_future_timetable_change_keeps_the_current_week_and_closure_cancels_prepared_future_work(): void
    {
        $this->travelTo(Carbon::parse('2026-11-24 08:00'));
        $manager = User::factory()->create(['role'=>'admin','active'=>true]);
        $group = TutorGroup::factory()->create();
        Apprentice::factory()->create(['tutor_group_id'=>$group->id]);
        $base = '/register-groups/'.$group->id;
        $data = ['record_version'=>1, 'first_day'=>'2026-11-24', 'start'=>'09:00', 'end'=>'16:00',
            'breaks_checked'=>1, 'planned_learning'=>'Practical workshop'];
        $this->actingAs($manager)->post($base.'/schedule',$data)->assertSessionHasNoErrors();
        $this->post($base.'/schedule',array_replace($data,['record_version'=>2,'first_day'=>'2026-12-03']))->assertSessionHasNoErrors();
        $this->artisan('gta:weekly-registers')->assertSuccessful();
        $current = TrainingSession::sole();
        $this->assertSame('2026-11-24',$current->session_date->toDateString());
        $this->travelTo(Carbon::parse('2026-11-25 08:00'));
        $this->post($base.'/open',['date'=>'2026-12-01','week'=>'2026-11-30'])->assertSessionHasNoErrors();
        $future = TrainingSession::latest('id')->firstOrFail();
        $this->assertSame('2026-12-01',$future->session_date->toDateString());
        $this->post($base.'/close',['record_version'=>3,'closed_on'=>'2026-11-29','reason'=>'Group training has ended.'])->assertSessionHasNoErrors();
        $this->assertSame('Cancelled',$future->fresh()->state);
        $this->assertSame('Planned',$current->fresh()->state);
        $this->travelTo(Carbon::parse('2026-12-03 08:00'));
        $this->artisan('gta:weekly-registers')->assertSuccessful();
        $this->assertDatabaseCount('training_sessions',2);
        $this->assertDatabaseCount('attendance_marks',0);
    }

}
