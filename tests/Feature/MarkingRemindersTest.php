<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\LearningTask;
use App\Models\MarkingReminder;
use App\Models\User;
use App\Support\MarkingReminders;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MarkingRemindersTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role = 'tutor'): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->save();

        return $u;
    }

    private function pending(?User $tutor = null, int $days = 8): array
    {
        $tutor ??= $this->actor();
        $a = Apprentice::factory()->create(['tutor_id' => $tutor->id]);
        $t = LearningTask::factory()->create(['apprentice_id' => $a->id]);
        $at = now()->subDays($days);
        $t->status = 'Awaiting marking';
        $t->submitted_at = $at;
        $t->submissions = [['version' => 1, 'submitted_at' => $at->toIso8601String(), 'template_hash' => $t->template['sha256'], 'answers' => ['private_answer' => 'ANSWERS MUST NOT APPEAR IN QUEUE'], 'decision' => null]];
        $t->save();

        return [$t->fresh(), $a, $tutor];
    }

    public function test_tutor_worklist_follows_current_assignment_and_does_not_expose_answers(): void
    {
        [$t, $a, $tutor] = $this->pending();
        [$other, $person] = $this->pending();
        $this->actingAs($tutor)->get('/marking')->assertOk()->assertSee($a->reference)->assertDontSee($person->reference)->assertDontSee('ANSWERS MUST NOT APPEAR IN QUEUE');
        $this->get('/marking?search='.$person->reference)->assertOk()->assertDontSee($person->reference.' ·')->assertSee('No submitted tasks match');
        $this->actingAs($this->actor('admin'))->get('/marking')->assertOk()->assertSee($a->reference)->assertSee($person->reference);
        $this->assertDatabaseCount('marking_reminders', 0);
    }

    #[TestWith(['apprentice'])] #[TestWith(['employer'])] #[TestWith(['mentor'])] #[TestWith(['functional_skills_tutor'])] #[TestWith(['careers_adviser'])] #[TestWith(['inclusion_lead'])]
    public function test_other_roles_cannot_read_or_generate_marking_reminders(string $role): void
    {
        $this->pending();
        $this->actingAs($this->actor($role))->get('/marking')->assertForbidden();
        $this->post('/marking/reminders')->assertForbidden();
        $this->assertDatabaseCount('marking_reminders', 0);
    }

    public function test_seven_day_boundary_missing_future_dates_and_source_checks_stay_distinct(): void
    {
        $this->travelTo(now()->startOfSecond());
        [$exact] = $this->pending(days: 7);
        [$older] = $this->pending(days: 7);
        $older->submitted_at = $older->submitted_at->subSecond();
        $history = $older->submissions;
        $history[0]['submitted_at'] = $older->submitted_at->toIso8601String();
        $older->submissions = $history;
        $older->save();
        [$missing] = $this->pending();
        $missing->submitted_at = null;
        $missing->save();
        [$future] = $this->pending(days: -1);
        [$unbound] = $this->pending();
        $unbound->submissions = [];
        $unbound->save();
        $r = MarkingReminders::generate();
        $this->assertSame(5, $r['awaiting']);
        $this->assertSame(2, $r['date_checks']);
        $this->assertSame(2, $r['overdue']);
        $this->assertSame(1, $r['source_or_owner_checks']);
        $this->assertSame(1, $r['eligible']);
        $this->assertDatabaseCount('marking_reminders', 0);
        $this->actingAs($this->actor('admin'))->get('/marking?view=date-check')->assertOk()->assertSee('Submission date needs checking')->assertDontSee($exact->apprentice->reference);
    }

    public function test_preview_is_read_only_and_recording_is_deduplicated_without_sending_mail(): void
    {
        Mail::fake();
        [$task, , $tutor] = $this->pending();
        $this->artisan('gta:marking-reminders')->expectsOutputToContain('"mode":"preview"')->assertSuccessful();
        $this->assertDatabaseCount('marking_reminders', 0);
        $this->artisan('gta:marking-reminders --record')->expectsOutputToContain('"created":1')->assertSuccessful();
        $this->artisan('gta:marking-reminders --record')->expectsOutputToContain('"already_recorded":1')->assertSuccessful();
        $reminder = MarkingReminder::firstOrFail();
        $this->assertSame($tutor->id, $reminder->recipient_id);
        $this->assertSame($task->id, $reminder->learning_task_id);
        $this->assertDatabaseCount('marking_reminders', 1);
        $this->assertDatabaseCount('marking_reminder_events', 1);
        $this->assertNull(DB::table('marking_reminder_events')->value('user_id'));
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_seen_reminder_does_not_mark_the_task_and_is_idempotent(): void
    {
        [$task, , $tutor] = $this->pending();
        MarkingReminders::generate(true);
        $reminder = MarkingReminder::firstOrFail();
        $before = $task->fresh()->getAttributes();
        $this->actingAs($tutor)->get('/marking')->assertOk()->assertSee('Mark reminder seen');
        $this->post('/marking/reminders/'.$reminder->id.'/seen')->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/marking/reminders/'.$reminder->id.'/seen')->assertSessionHasNoErrors();
        $this->assertNotNull($reminder->fresh()->seen_at);
        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->assertSame(1, DB::table('marking_reminder_events')->where('event', 'seen')->count());
        $this->get('/marking')->assertOk()->assertSee($task->apprentice->reference)->assertDontSee('Mark reminder seen');
    }

    public function test_reassignment_revokes_old_reminder_access_and_records_once_for_the_new_tutor(): void
    {
        [$task, $a, $old] = $this->pending();
        MarkingReminders::generate(true);
        $oldReminder = MarkingReminder::firstOrFail();
        $new = $this->actor();
        $a->tutor_id = $new->id;
        $a->save();
        $this->actingAs($old)->get('/marking')->assertOk()->assertDontSee($a->reference);
        $this->post('/marking/reminders/'.$oldReminder->id.'/seen')->assertForbidden();
        $this->assertSame(1, MarkingReminders::generate(true)['created']);
        $newReminder = MarkingReminder::where('recipient_id', $new->id)->firstOrFail();
        $this->actingAs($new)->post('/marking/reminders/'.$oldReminder->id.'/seen')->assertForbidden();
        $this->post('/marking/reminders/'.$newReminder->id.'/seen')->assertSessionHasNoErrors();
        $this->assertDatabaseCount('marking_reminders', 2);
    }

    public function test_deadline_changes_do_not_duplicate_reminders_and_new_submissions_get_their_own_record(): void
    {
        [$task, , $tutor] = $this->pending();
        MarkingReminders::generate(true);
        $oldReminder = MarkingReminder::firstOrFail();
        $task->due_date = today()->addDay();
        $task->version++;
        $task->save();
        $this->assertSame(0, MarkingReminders::generate(true)['created']);
        $history = $task->submissions;
        $history[0]['decision'] = ['status' => 'Referred'];
        $history[] = [...$history[0], 'version' => 2, 'decision' => null];
        $task->submissions = $history;
        $task->save();
        $this->assertSame(1, MarkingReminders::generate(true)['created']);
        $this->assertDatabaseCount('marking_reminders', 2);
        $this->actingAs($tutor)->post('/marking/reminders/'.$oldReminder->id.'/seen')->assertSessionHasErrors('reminder');
    }

    public function test_approval_removes_work_from_live_lists_and_old_reminders_cannot_be_acknowledged(): void
    {
        [$task, , $tutor] = $this->pending();
        MarkingReminders::generate(true);
        $reminder = MarkingReminder::firstOrFail();
        $task->status = 'Approved';
        $task->save();
        $this->actingAs($tutor)->get('/marking?view=all')->assertOk()->assertDontSee($task->apprentice->reference);
        $this->post('/marking/reminders/'.$reminder->id.'/seen')->assertSessionHasErrors('reminder');
        $this->assertSame(0, MarkingReminders::generate(true)['eligible']);
        $this->assertNull($reminder->fresh()->seen_at);
    }

    public function test_inactive_wrong_role_and_missing_tutors_receive_no_reminder(): void
    {
        [$one, $a, $inactive] = $this->pending();
        $inactive->active = false;
        $inactive->save();
        [$two, $b] = $this->pending();
        $b->tutor_id = null;
        $b->save();
        $this->pending($this->actor('employer'));
        $r = MarkingReminders::generate(true);
        $this->assertSame(3, $r['source_or_owner_checks']);
        $this->assertSame(0, $r['created']);
        $this->actingAs($inactive)->get('/marking')->assertForbidden();
    }

    public function test_administrator_records_are_audited_and_tutors_cannot_generate_for_colleagues(): void
    {
        [, , $tutor] = $this->pending();
        $this->actingAs($tutor)->post('/marking/reminders')->assertForbidden();
        $admin = $this->actor('admin');
        $this->actingAs($admin)->post('/marking/reminders')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($admin->id, DB::table('marking_reminder_events')->value('user_id'));
        $this->assertSame('administrator', DB::table('marking_reminder_events')->value('origin'));
        $this->post('/marking/reminders/'.MarkingReminder::firstOrFail()->id.'/seen')->assertForbidden();
    }

    public function test_failed_audit_rolls_back_the_entire_reminder_batch(): void
    {
        $this->pending();
        $this->pending();
        \Tests\Support\AuditFault::install('reject_reminder_event','marking_reminder_events','Fictional audit failure');
        $this->actingAs($this->actor('admin'))->post('/marking/reminders')->assertStatus(500);
        $this->assertDatabaseCount('marking_reminders', 0);
        $this->assertDatabaseCount('marking_reminder_events', 0);
    }

    public function test_failed_seen_audit_does_not_leave_a_partial_acknowledgement(): void
    {
        [, , $tutor] = $this->pending();
        MarkingReminders::generate(true);
        $r = MarkingReminder::firstOrFail();
        \Tests\Support\AuditFault::install('reject_reminder_seen','marking_reminder_events','Fictional audit failure','seen');
        $this->actingAs($tutor)->post('/marking/reminders/'.$r->id.'/seen')->assertStatus(500);
        $this->assertNull($r->fresh()->seen_at);
    }

    public function test_current_account_status_is_rechecked_before_recording(): void
    {
        $this->pending();
        $admin = $this->actor('admin');
        DB::table('users')->where('id', $admin->id)->update(['active' => false]);
        try {
            MarkingReminders::generate(true, $admin);
            $this->fail('An inactive current account must not record reminders.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertDatabaseCount('marking_reminders', 0);
    }

    public function test_array_or_oversized_search_filters_are_rejected_without_changing_scope(): void
    {
        $this->actingAs($this->actor('admin'))->getJson('/marking?search[]=invalid')->assertUnprocessable()->assertJsonValidationErrors('search');
        $this->getJson('/marking?search='.str_repeat('x', 251))->assertUnprocessable()->assertJsonValidationErrors('search');
        $this->assertDatabaseCount('marking_reminders', 0);
    }

    public function test_names_and_references_are_escaped_and_seen_history_does_not_broaden_scope(): void
    {
        [$task, $a, $tutor] = $this->pending();
        $a->first_name = '<script>fake()</script>';
        $a->save();
        $this->actingAs($tutor)->get('/marking')->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>fake()</script>', false);
        $this->assertSame('Awaiting marking', $task->fresh()->status);
    }
}
