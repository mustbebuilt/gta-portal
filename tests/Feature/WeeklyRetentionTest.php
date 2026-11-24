<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\AttendanceMark;
use App\Models\IndividualDeliveryPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\WeeklyRetentionCheck;
use App\Models\WeeklyRetentionReminder;
use App\Support\DeliveryPlan;
use App\Support\WeeklyRetention;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class WeeklyRetentionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role, bool $verified = true): User
    {
        return User::factory()->create(['role' => $role, 'email_verified_at' => $verified ? now() : null]);
    }

    private function plan(Apprentice $apprentice): void
    {
        $plan = new IndividualDeliveryPlan;
        $plan->apprentice_id = $apprentice->id;
        $plan->active = true;
        $plan->draft = [];
        $plan->history = [];
        $plan->approved = [
            'identity' => DeliveryPlan::identity($apprentice),
            'details' => ['target_hours' => 200],
            'blocks' => [['number' => 1, 'start' => '2026-10-26', 'end' => '2026-11-22', 'hours' => 200]],
        ];
        $plan->save();
    }

    private function mark(Apprentice $apprentice, string $date, ?string $code, int $minutes = 0): AttendanceMark
    {
        $session = TrainingSession::factory()->create(['members' => [$apprentice->id], 'session_date' => $date]);
        return AttendanceMark::create([
            'training_session_id' => $session->id, 'apprentice_id' => $apprentice->id, 'code' => $code,
            'otj_minutes' => $minutes, 'otj_confirmed' => $code === 'O', 'parts' => [], 'recorded_by' => $session->created_by,
        ]);
    }

    public function test_prior_sunday_cutoff_and_exact_ten_percent_threshold(): void
    {
        // The plan accrues 100 of 200 hours by Monday 9 November.
        $this->travelTo(CarbonImmutable::parse('2026-11-09 18:00:00', 'Europe/London'));
        $apprentice = Apprentice::factory()->create(['start_date' => '2026-10-26']);
        $this->plan($apprentice);
        $sunday = $this->mark($apprentice, '2026-11-08', 'O', 4800);
        $this->mark($apprentice, '2026-11-09', 'O', 1500);

        $evidence = WeeklyRetention::evidence($apprentice, WeeklyRetention::monday());
        $this->assertEquals(['expected' => 100, 'recorded' => 80, 'behind' => 20], $evidence['otj']);
        $sunday->update(['otj_minutes' => 5400]);
        $this->assertEquals(10, WeeklyRetention::evidence($apprentice, WeeklyRetention::monday())['otj']['behind']);
        $sunday->update(['otj_minutes' => 5401]);
        $this->assertNull(WeeklyRetention::evidence($apprentice, WeeklyRetention::monday())['otj']);

        $sunday->update(['otj_confirmed' => false]);
        $evidence = WeeklyRetention::evidence($apprentice, WeeklyRetention::monday());
        $this->assertNull($evidence['otj']);
        $this->assertContains('Unconfirmed OTJ or attendance records need checking', $evidence['checks']);
    }

    public function test_weekly_portal_and_email_reminders_are_private_and_repeat_once_per_role_per_monday(): void
    {
        Event::fake([MessageSent::class]);
        $this->travelTo(CarbonImmutable::parse('2026-11-09 09:00:00', 'Europe/London'));
        $tutor = $this->actor('tutor');
        $learner = $this->actor('apprentice');
        $apprentice = Apprentice::factory()->create([
            'first_name' => 'Fictionalweekly', 'start_date' => '2026-10-26', 'tutor_id' => $tutor->id, 'user_id' => $learner->id,
        ]);
        $this->mark($apprentice, '2026-10-27', 'W');
        $second = $this->mark($apprentice, '2026-11-03', 'S');
        $this->assertSame([], WeeklyRetention::evidence($apprentice, WeeklyRetention::monday())['attendance']);
        $second->update(['code' => 'A']);

        $this->artisan('gta:weekly-retention', ['--record' => true, '--send' => true])->assertSuccessful();
        $this->artisan('gta:weekly-retention', ['--record' => true, '--send' => true])->assertSuccessful();
        Event::assertDispatchedTimes(MessageSent::class, 2);
        $this->assertSame(2, WeeklyRetentionReminder::where('email_state', 'sent')->count());
        $check = WeeklyRetentionCheck::sole();
        $this->actingAs($tutor)->get(route('weekly-retention.reminders'))->assertOk()->assertSee('Kept at work');
        $this->get(route('weekly-retention.index'))->assertForbidden();
        $this->actingAs($learner)->get(route('weekly-retention.reminders'))->assertOk()->assertSee('Absent');
        $this->get(route('experience.home'))->assertOk()->assertSee('Open this week’s training reminder');
        $this->actingAs($this->actor('tutor'))->get(route('weekly-retention.reminders'))->assertOk()->assertDontSee('Fictionalweekly');
        $this->post(route('weekly-retention.reviewed', $check))->assertForbidden();

        $this->actingAs($this->actor('inclusion_member'))->get(route('weekly-retention.index'))->assertOk()->assertSee('Fictionalweekly');
        $this->post(route('weekly-retention.reviewed', $check))->assertRedirect();
        $this->get(route('weekly-retention.index'))->assertOk()->assertDontSee('Fictionalweekly');
        $this->get(route('weekly-retention.index', ['view' => 'all']))->assertOk()->assertSee('Fictionalweekly');

        $this->travel(7)->days();
        $this->artisan('gta:weekly-retention', ['--record' => true, '--send' => true])->assertSuccessful();
        Event::assertDispatchedTimes(MessageSent::class, 4);
        $this->assertSame(4, WeeklyRetentionReminder::where('email_state', 'sent')->count());
        $this->assertDatabaseCount('weekly_retention_checks', 2);
        $this->mark($apprentice, '2026-11-17', 'O', 360);
        $this->travel(7)->days();
        $this->artisan('gta:weekly-retention', ['--record' => true, '--send' => true])->assertSuccessful();
        Event::assertDispatchedTimes(MessageSent::class, 4);
    }

    public function test_unverified_email_holds_delivery_but_keeps_the_portal_reminder(): void
    {
        Event::fake([MessageSent::class]);
        $this->travelTo(CarbonImmutable::parse('2026-11-09 09:00:00', 'Europe/London'));
        $learner = $this->actor('apprentice', false);
        $apprentice = Apprentice::factory()->create(['start_date' => '2026-10-26', 'user_id' => $learner->id]);
        $this->plan($apprentice);
        $this->artisan('gta:weekly-retention', ['--record' => true, '--send' => true])->assertSuccessful();
        $this->assertContains('No training records to assess the OTJ gap', WeeklyRetentionCheck::sole()->evidence['checks']);
        $this->assertDatabaseCount('weekly_retention_reminders', 0);

        $this->mark($apprentice, '2026-11-12', 'O', 4800);
        $this->travel(7)->days();
        $this->artisan('gta:weekly-retention', ['--record' => true, '--send' => true])->assertSuccessful();
        Event::assertNotDispatched(MessageSent::class);
        $this->assertSame('held', WeeklyRetentionReminder::sole()->email_state);
        $this->actingAs($learner)->get(route('weekly-retention.reminders'))->assertOk()->assertSee('Off-the-job training is');
    }

    public function test_monday_schedule_is_nine_in_london_in_summer_and_winter(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'gta:weekly-retention'));
        $this->assertNotNull($event);
        $this->assertSame('0 9 * * 1', $event->expression);
        $this->assertSame('Europe/London', $event->timezone);
        config(['weekly_retention.scheduled' => true]);
        foreach (['2026-07-06 08:00:00', '2026-11-09 09:00:00'] as $utc) {
            $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
            $this->assertTrue($event->isDue($this->app));
            $this->travel(1)->minutes();
            $this->assertFalse($event->isDue($this->app));
        }
    }
}
