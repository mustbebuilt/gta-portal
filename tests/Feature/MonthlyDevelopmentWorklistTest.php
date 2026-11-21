<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\AttendanceMark;
use App\Models\TrainingSession;
use App\Models\TutorGroup;
use App\Models\User;
use App\Support\ApprenticeReviewData;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class MonthlyDevelopmentWorklistTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_current_month_worklist_links_latest_attendance_and_clears_after_save(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 18:00'));
        $tutor = User::factory()->create(['role'=>'tutor','active'=>true]);
        $other = User::factory()->create(['role'=>'tutor','active'=>true]);
        $group = TutorGroup::factory()->create(['tutor_id'=>$tutor->id]);
        $foreign = TutorGroup::factory()->create(['tutor_id'=>$other->id]);
        $newApprentice = fn ($name, $owner, $assigned, $start = '2026-10-01') => Apprentice::factory()->create([
            'first_name'=>$name, 'last_name'=>'Example', 'tutor_id'=>$owner->id,
            'tutor_group_id'=>$assigned->id, 'start_date'=>$start
        ]);
        $a = $newApprentice('Attended', $tutor, $group);
        $absent = $newApprentice('NoObservation', $tutor, $group);
        $newApprentice('ForeignScope', $other, $foreign);
        $newApprentice('FutureStart', $tutor, $group, '2026-12-01');
        foreach (['2026-10-30', '2026-11-05', '2026-11-12', '2026-11-25'] as $day) {
            $session = TrainingSession::factory()->create([
                'tutor_group_id'=>$group->id, 'session_date'=>$day, 'members'=>[$a->id, $absent->id],
            ]);
            if ($day <= '2026-11-20') {
                AttendanceMark::create(['training_session_id'=>$session->id,'apprentice_id'=>$a->id,'code'=>'O',
                    'arrival'=>'09:00','departure'=>'16:00','recorded_by'=>$tutor->id]);
                AttendanceMark::create(['training_session_id'=>$session->id,'apprentice_id'=>$absent->id,'code'=>'A',
                    'recorded_by'=>$tutor->id]);
            }
        }
        $attended = TrainingSession::whereDate('session_date','2026-11-12')->sole();
        $worklist = '/sessions?group='.$group->id;
        $direct = '/sessions/'.$attended->id.'?snapshot_for='.$a->id;
        $this->actingAs($tutor)->get($worklist)->assertOk()->assertSee('2 outstanding')
            ->assertSee($direct.'#monthly-development-'.$a->id,false)->assertSee('No attended register available this month')
            ->assertDontSee('ForeignScope')->assertDontSee('FutureStart');
        $this->get($direct)->assertOk()->assertSee('id="monthly-development-'.$a->id.'"  open ',false);
        $this->post('/sessions/'.$attended->id, [
            'version'=>1,'correction_reason'=>'Add workshop record','delivered_learning'=>'Workshop practice',
            'marks'=>[$a->id=>['code'=>'O','arrival'=>'09:00','departure'=>'16:00'],$absent->id=>['code'=>'A']],
            'development'=>[$a->id=>['enabled'=>1,'version'=>0,
                'ratings'=>array_fill_keys(array_keys(ApprenticeReviewData::DEVELOPMENT_AREAS),'Developing')]],
        ])->assertSessionHasNoErrors();
        $this->get($worklist)->assertOk()->assertSee('1 outstanding')
            ->assertDontSee('#monthly-development-'.$a->id,false)->assertSee('NoObservation Example');
    }
}
