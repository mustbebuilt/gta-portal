<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\AttendanceMark;
use App\Models\Programme;
use App\Models\RegisterTrainingRecord;
use App\Models\TrainingSession;
use App\Models\TutorGroup;
use App\Models\User;
use App\Support\AttendanceMath;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AttendanceWorkflowTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role = 'admin'): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->save();

        return $u;
    }

    private function fixtureSession(): array
    {
        $u = $this->actor('tutor');
        $g = TutorGroup::factory()->create(['tutor_id' => $u->id]);
        $l = $this->actor('apprentice');
        $a = Apprentice::factory()->create(['tutor_id' => $u->id, 'user_id' => $l->id, 'tutor_group_id' => $g->id]);
        $s = TrainingSession::factory()->create(['tutor_group_id' => $g->id, 'members' => [$a->id]]);

        return [$s, $a, $u, $l];
    }

    private function mark(int $id, array $changes = []): array
    {
        return ['version' => 1, 'delivered_learning' => 'New inspection learning delivered', 'marks' => [$id => [...['code' => 'O', 'arrival' => '09:00', 'departure' => '16:00', 'otj_confirmed' => 1], ...$changes]]];
    }

    public function test_session_planner_snapshots_roster_without_attendance_or_hours(): void
    {
        $g = TutorGroup::factory()->create();
        $a = Apprentice::factory()->create(['tutor_group_id' => $g->id]);
        $this->actingAs($this->actor())->post('/sessions', ['tutor_group_id' => $g->id, 'name' => 'Fictional planned workshop', 'session_date' => '2026-12-01', 'start_time' => '09:00', 'end_time' => '16:00', 'planned_learning' => 'Safe inspection methods', 'breaks_checked' => 1, 'breaks' => [['start' => '12:00', 'end' => '12:30']]])->assertRedirect()->assertSessionHasNoErrors();
        $s = TrainingSession::firstOrFail();
        $this->assertSame([$a->id], $s->members);
        $this->assertDatabaseCount('attendance_marks', 0);
        $this->get('/sessions/'.$s->id)->assertOk()->assertSee('Fictional planned workshop');
        $this->get('/sessions')->assertOk();
    }

    public function test_time_subtraction_handles_overlapping_exclusions_and_late_arrival(): void
    {
        $parts = AttendanceMath::parts('09:15', '16:15', '09:00', '16:00', [['start' => '12:00', 'end' => '12:30'], ['start' => '12:15', 'end' => '12:45']]);
        $this->assertSame(360, array_sum(array_map(fn ($p) => $p[1] - $p[0], $parts)));
    }

    public function test_register_records_exact_confirmed_minutes_and_reopens(): void
    {
        [$s,$a,$u] = $this->fixtureSession();
        $this->actingAs($u)->post('/sessions/'.$s->id, $this->mark($a->id))->assertRedirect()->assertSessionHasNoErrors();
        $m = AttendanceMark::firstOrFail();
        $this->assertSame(390, $m->otj_minutes);
        $this->assertTrue($m->otj_confirmed);
        $this->get('/sessions/'.$s->id)->assertOk()->assertSee('New inspection learning delivered');
        $this->get('/apprentices/'.$a->id.'/attendance')->assertOk()->assertSee('100%')->assertSee('6.50 hrs');
    }

    public function test_unconfirmed_attendance_counts_for_attendance_but_not_otj(): void
    {
        [$s,$a,$u] = $this->fixtureSession();
        $this->actingAs($u)->post('/sessions/'.$s->id, $this->mark($a->id, ['otj_confirmed' => 0]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(AttendanceMark::firstOrFail()->otj_minutes);
        $this->get('/apprentices/'.$a->id.'/attendance')->assertOk()->assertSee('100%')->assertSee('0.00 hrs');
    }

    public function test_reductions_need_explanation_and_never_exceed_actual_time(): void
    {
        [$s,$a,$u] = $this->fixtureSession();
        $this->actingAs($u)->post('/sessions/'.$s->id, $this->mark($a->id, ['minutes' => 500]))->assertSessionHasErrors('marks');
        $this->post('/sessions/'.$s->id, $this->mark($a->id, ['minutes' => 360]))->assertSessionHasErrors('marks');
        $this->post('/sessions/'.$s->id, $this->mark($a->id, ['minutes' => 360, 'adjustment' => 'Thirty minutes of non-eligible activity excluded.']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(360, AttendanceMark::firstOrFail()->otj_minutes);
    }

    public function test_non_attended_codes_cannot_generate_otj(): void
    {
        [$s,$a,$u] = $this->fixtureSession();
        $this->actingAs($u)->post('/sessions/'.$s->id, $this->mark($a->id, ['code' => 'A']))->assertSessionHasErrors('marks');
        $this->assertDatabaseCount('attendance_marks', 0);
    }

    public function test_future_session_cannot_record_actual_attendance(): void
    {
        [$s,$a,$u] = $this->fixtureSession();
        $s->session_date = today()->addDays(2);
        $s->save();
        $this->actingAs($u)->post('/sessions/'.$s->id, $this->mark($a->id))->assertSessionHasErrors('marks');
    }

    public function test_overlapping_confirmed_training_is_not_double_counted(): void
    {
        [$s,$a,$u] = $this->fixtureSession();
        $this->actingAs($u)->post('/sessions/'.$s->id, $this->mark($a->id))->assertRedirect()->assertSessionHasNoErrors();
        $second = TrainingSession::factory()->create(['tutor_group_id' => $s->tutor_group_id, 'members' => [$a->id]]);
        $this->post('/sessions/'.$second->id, $this->mark($a->id))->assertSessionHasErrors('marks');
        $this->assertDatabaseCount('attendance_marks', 1);
    }

    public function test_cancellation_excludes_hours_and_attendance_but_keeps_source_mark(): void
    {
        [$s,$a,$u] = $this->fixtureSession();
        $this->actingAs($u)->post('/sessions/'.$s->id, $this->mark($a->id))->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/sessions/'.$s->id.'/cancel', ['version' => 2, 'reason' => 'GTA closure'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('attendance_marks', 1);
        $this->get('/apprentices/'.$a->id.'/attendance')->assertOk()->assertSee('0.00 hrs')->assertSee('No expected sessions recorded');
    }

    public function test_other_tutor_apprentice_and_employer_cannot_open_register(): void
    {
        [$s,$a,$u,$l] = $this->fixtureSession();
        foreach ([$this->actor('tutor'), $l, $this->actor('employer')] as $actor) {
            $this->actingAs($actor)->get('/sessions/'.$s->id)->assertForbidden();
            $this->post('/sessions/'.$s->id, $this->mark($a->id))->assertForbidden();
        }$this->actingAs($l)->get('/apprentices/'.$a->id.'/attendance')->assertOk();
    }

    public function test_group_tutor_does_not_gain_access_to_other_main_tutor_apprentice(): void
    {
        [$s,$a,$u] = $this->fixtureSession();
        $a->tutor_id = $this->actor('tutor')->id;
        $a->save();
        $this->actingAs($u)->get('/sessions/'.$s->id)->assertForbidden();
        $this->get('/sessions')->assertOk()->assertDontSee('Fictional training session');
    }

    public function test_marks_for_non_roster_apprentice_and_stale_updates_are_rejected(): void
    {
        [$s,$a,$u] = $this->fixtureSession();
        $other = Apprentice::factory()->create();
        $this->actingAs($u)->post('/sessions/'.$s->id, $this->mark($other->id))->assertSessionHasErrors('marks');
        $this->post('/sessions/'.$s->id, $this->mark($a->id))->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/sessions/'.$s->id, $this->mark($a->id))->assertSessionHasErrors('version');
    }

    public function test_attendance_exclusions_unmarked_and_two_week_flag_match_source_rules(): void
    {
        $this->travelTo(Carbon::parse('2026-11-11'));
        $rows = [];
        foreach (['A', 'S', 'C', 'B', 'E'] as $i => $code) {
            $rows[] = ['date' => ['2026-10-19', '2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29'][$i], 'code' => $code, 'cancelled' => false, 'minutes' => 0];
        }$r = AttendanceMath::report($rows);
        $this->assertSame(2, $r['expected']);
        $this->assertSame(3, $r['excluded']);
        $this->assertEquals(0, $r['percent']);
        $this->assertCount(1, $r['pairs']);
        $rows[] = ['date' => '2026-11-02', 'code' => null, 'cancelled' => false, 'minutes' => null];
        $this->assertNull(AttendanceMath::report($rows)['percent']);
    }

    public function test_current_incomplete_week_and_week_gaps_are_not_consecutive_week_flags(): void
    {
        $this->travelTo(Carbon::parse('2026-11-11'));
        $rows = [['date' => '2026-10-19', 'code' => 'A', 'cancelled' => false, 'minutes' => 0], ['date' => '2026-11-09', 'code' => 'A', 'cancelled' => false, 'minutes' => 0]];
        $this->assertCount(0, AttendanceMath::report($rows)['pairs']);
    }

    public function test_subject_planner_uses_weekly_times_and_prefills_actual_delivery_as_a_draft(): void
    {
        $this->travelTo(Carbon::parse('2026-12-01 08:00'));
        $tutor = $this->actor('tutor');
        $group = TutorGroup::factory()->create(['tutor_id' => $tutor->id]);
        $programme = Programme::factory()->create(['standard_code' => 'ST0499', 'version' => '1.3', 'active' => true]);
        $member = Apprentice::factory()->create(['programme_id' => $programme->id, 'tutor_id' => $tutor->id, 'tutor_group_id' => $group->id]);
        $group->forceFill(['weekly_register' => ['revisions' => [[
            'first_day' => '2026-12-01', 'start' => '09:00', 'end' => '16:00',
            'planned_learning' => 'Previous generic weekly text',
            'breaks' => [['start' => '12:00', 'end' => '13:00']],
        ]]]])->save();
        $subjects = ['voc-autocare-b1-t3', 'voc-autocare-b1-t4'];
        $this->actingAs($tutor)->get('/sessions?group='.$group->id.'&plan_date=2026-12-01')
            ->assertOk()->assertSee('Subject from the scheme of work')
            ->assertSee('Using the weekly schedule, 09:00–16:00')->assertSee('Vehicle safety inspection');
        $options = route('attendance.plan-options', ['group' => $group, 'date' => '2026-12-01']);
        $this->getJson($options)->assertOk()->assertJsonPath('timing.start', '09:00')->assertJsonFragment(['subjectId' => $subjects[0]]);
        $data = ['planner' => 'subjects', 'tutor_group_id' => $group->id, 'session_date' => '2026-12-01', 'timing' => 'weekly', 'subjects' => $subjects];
        $this->post('/sessions', array_replace($data, ['subjects' => ['voc-panel-b1-t3']]))->assertSessionHasErrors('subjects');
        $this->post('/sessions', array_replace($data, ['session_date' => '2026-12-02']))->assertSessionHasErrors('timing');
        $this->assertDatabaseCount('training_sessions', 0);
        $result = $this->post('/sessions', $data);
        $session = TrainingSession::sole();
        $result->assertRedirect(route('attendance.show', $session));
        $this->assertSame($subjects, array_column($session->planned_subjects, 'subjectId'));
        $this->assertSame([['start' => '12:00', 'end' => '13:00']], $session->breaks);
        $this->assertSame('2026-11-30', $session->regular_week);
        $this->assertDatabaseCount('attendance_marks', 0);
        $this->assertDatabaseCount('register_training_records', 0);
        $this->get(route('attendance.show', $session))->assertOk()->assertSee('Planned subjects are filled in below')
            ->assertSee('Vehicle safety inspection')->assertSee('Basic brake and tyre condition check');
        $this->actingAs($this->actor('tutor'))->getJson($options)->assertForbidden();
        $this->post('/sessions', $data)->assertForbidden();

        $this->travelTo(Carbon::parse('2026-12-01 17:00'));
        $this->actingAs($tutor)->post(route('attendance.save', $session), [
            'version' => $session->version, 'weekly_confirmed' => 1,
            'delivered_learning' => 'Vehicle safety inspection was delivered.',
            'marks' => [$member->id => ['code' => 'O', 'arrival' => '09:00', 'departure' => '16:00']],
            'training' => ['subjects' => [$subjects[0]]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([$subjects[0]], array_column(RegisterTrainingRecord::sole()->details['items'], 'subjectId'));
        $this->assertNull(AttendanceMark::sole()->otj_minutes);
        $this->get(route('attendance.show', $session))->assertOk()->assertDontSee('Planned subjects are filled in below');
    }

    public function test_subject_planner_only_requires_manual_times_when_no_schedule_applies(): void
    {
        $tutor = $this->actor('tutor');
        $group = TutorGroup::factory()->create(['tutor_id' => $tutor->id]);
        $programme = Programme::factory()->create(['standard_code' => 'ST0499', 'version' => '1.3', 'active' => true]);
        Apprentice::factory()->create(['programme_id' => $programme->id, 'tutor_id' => $tutor->id, 'tutor_group_id' => $group->id]);
        $date = today()->addDay()->toDateString();
        $data = ['planner' => 'subjects', 'tutor_group_id' => $group->id, 'session_date' => $date,
            'timing' => 'custom', 'subjects' => ['voc-autocare-b1-t3']];
        $this->actingAs($tutor)->getJson(route('attendance.plan-options', ['group' => $group, 'date' => $date]))
            ->assertOk()->assertJsonPath('timing', null);
        $this->post('/sessions', $data)->assertSessionHasErrors(['start_time', 'end_time', 'breaks_checked']);
        $this->post('/sessions', $data + ['start_time' => '10:00', 'end_time' => '14:00', 'breaks_checked' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('10:00', TrainingSession::sole()->start_time);
        $this->assertNull(TrainingSession::sole()->regular_week);
    }

    public function test_group_without_mapped_scheme_can_plan_an_other_subject(): void
    {
        $tutor = $this->actor('tutor');
        $group = TutorGroup::factory()->create(['tutor_id' => $tutor->id]);
        Apprentice::factory()->create(['tutor_id' => $tutor->id, 'tutor_group_id' => $group->id]);
        $this->actingAs($tutor)->post('/sessions', [
            'planner' => 'subjects', 'tutor_group_id' => $group->id, 'session_date' => today()->toDateString(),
            'timing' => 'custom', 'other_subject' => 'Fictional site induction',
            'start_time' => '10:00', 'end_time' => '14:00', 'breaks_checked' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $session = TrainingSession::sole();
        $this->assertSame('Fictional site induction', $session->planned_learning);
        $this->assertSame([], $session->planned_subjects);
        $this->assertDatabaseCount('register_training_records', 0);
    }

    public function test_delivery_register_accepts_dropdown_subjects_and_custom_notes_together(): void
    {
        [$session, $member, $tutor] = $this->fixtureSession();
        $programme = Programme::factory()->create(['standard_code' => 'ST0499', 'version' => '1.3', 'active' => true]);
        $member->forceFill(['programme_id' => $programme->id])->save();
        $url = route('attendance.show', $session);
        $this->actingAs($tutor)->get($url)->assertOk()
            ->assertSee('Vocational training subject')->assertSee('English and maths subject')
            ->assertSee('Equality, diversity, British Values and Prevent subject')
            ->assertSee('Vehicle safety inspection')->assertSee('Number and calculations')
            ->assertSee('Safeguarding and Prevent')->assertSee('British Values')->assertSee('Custom');
        $data = $this->mark($member->id, ['otj_confirmed' => 0]);
        $data['weekly_confirmed'] = 1;
        $data['training'] = [
            'subjects' => ['voc-autocare-b1-t3', 'fs-maths-number', 'cc-british-values'],
            'vocational' => 'Fictional partial inspection',
            'functional_skills' => 'Fictional maths recap',
            'themes' => 'Fictional discussion',
        ];
        $this->post($url, $data)->assertRedirect()->assertSessionHasNoErrors();
        $items = collect(RegisterTrainingRecord::sole()->details['items']);
        $this->assertSame($data['training']['subjects'], $items->take(3)->pluck('subjectId')->all());
        $this->assertSame(['vocational', 'functional_skills', 'themes'], $items->skip(3)->pluck('area')->all());
        $this->assertNull(AttendanceMark::sole()->otj_minutes);
        $this->get($url)->assertOk()->assertSee('Fictional partial inspection')->assertSee('Fictional maths recap')->assertSee('Fictional discussion');
    }
}
