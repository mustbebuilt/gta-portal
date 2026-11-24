<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\ProgrammeOutcome;
use App\Models\User;
use App\Models\WeeklyRetentionCheck;
use App\Models\WeeklyRetentionReminder;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

final class WeeklyRetention
{
    public static function monday(): CarbonImmutable
    {
        return CarbonImmutable::now('Europe/London')->startOfWeek(CarbonImmutable::MONDAY);
    }

    public static function canReceive(User $user, Apprentice $apprentice): bool
    {
        return LearningAccess::tutor($user, $apprentice) || LearningAccess::learner($user, $apprentice);
    }

    public static function currentReminderCount(User $user, array $apprenticeIds): int
    {
        return WeeklyRetentionReminder::where('user_id', $user->id)->whereHas('check',
            fn ($q) => $q->where('week_of', self::monday()->toDateString())->whereIn('apprentice_id', $apprenticeIds)
        )->count();
    }

    public static function evidence(Apprentice $apprentice, CarbonImmutable $monday): ?array
    {
        $from = $apprentice->start_date?->toDateString();
        $date = $monday->toDateString();
        if ($from === null || $from >= $date) {
            return null;
        }

        $context = ProgrammeOutcome::where('apprentice_id', $apprentice->id)->first()?->context ?? [];
        if (!empty($context['epa_closure']['archived_at'])
            || in_array($context['status'] ?? null, ['Break in learning', 'Completed', 'Withdrawn', 'Transferred'], true)) {
            return null;
        }

        $window = array_values(array_filter(OtjPosition::forApprentice($apprentice)['rows'],
            fn ($row) => $row['date'] >= $from && $row['date'] < $date));
        $expected = OtjPosition::progressiveHours(DeliveryPlan::approved($apprentice), $date);
        $recordedMinutes = array_sum(array_column($window, 'minutes'));
        $sessionRows = array_map(fn ($row) => $row + ['cancelled' => $row['state'] === 'Cancelled'],
            array_filter($window, fn ($row) => $row['kind'] === 'session'));
        $recentSessions = AttendanceMath::recentWarningSessions($sessionRows, $from, $date);
        $attendance = AttendanceMath::missedWarningSessions($recentSessions);
        $dataChecks = [];
        $otj = null;

        if ($expected === null || $expected <= 0) {
            $dataChecks[] = 'No positive approved OTJ target due yet';
        } elseif ($window === [] || !array_filter($window, fn ($row) => $row['minutes'] > 0
            || ($row['kind'] === 'session' && $row['code'] !== null && $row['state'] !== 'Cancelled'))) {
            $dataChecks[] = 'No training records to assess the OTJ gap';
        } elseif ($recordedMinutes * 1000 <= 9 * (int) round($expected * 6000)) {
            // Compare in integer units so the exact 10% boundary qualifies.
            $unconfirmed = array_filter($window, fn ($row) => in_array($row['state'], [
                'Register not marked', 'OTJ not confirmed', 'Minutes need checking', 'Awaiting approval / check',
            ], true) || ($row['data_check'] ?? false));
            if ($unconfirmed !== []) {
                $dataChecks[] = 'Unconfirmed OTJ or attendance records need checking';
            } else {
                $otj = [
                    'expected' => $expected,
                    'recorded' => round($recordedMinutes / 60, 2),
                    'behind' => round(100 * (1 - $recordedMinutes / (60 * $expected)), 1),
                ];
            }
        }

        if (in_array(null, array_column($recentSessions, 'code'), true)) {
            $dataChecks[] = 'A recent training register has no attendance mark';
        } elseif (count($recentSessions) < 2) {
            $dataChecks[] = 'Fewer than two recorded training sessions';
        }

        return ['attendance' => $attendance, 'otj' => $otj, 'checks' => $dataChecks];
    }

    public static function capture(Apprentice $apprentice, CarbonImmutable $monday): void
    {
        $evidence = self::evidence($apprentice, $monday);
        if (!$evidence || (!$evidence['attendance'] && !$evidence['otj'] && !$evidence['checks'])) {
            return;
        }

        DB::transaction(function () use ($apprentice, $monday, $evidence): void {
            $check = WeeklyRetentionCheck::firstOrCreate([
                'apprentice_id' => $apprentice->id, 'week_of' => $monday->toDateString(),
            ], ['evidence' => $evidence]);
            if (!$check->evidence['attendance'] && !$check->evidence['otj']) {
                return;
            }

            $recipients = User::whereIn('id', array_filter([$apprentice->tutor_id, $apprentice->user_id]))->get();
            foreach ($recipients as $recipient) {
                if (self::canReceive($recipient, $apprentice)) {
                    $check->reminders()->firstOrCreate(['user_id' => $recipient->id]);
                }
            }
        });
    }

    public static function send(WeeklyRetentionReminder $reminder, Apprentice $apprentice): void
    {
        $user = User::find($reminder->user_id);
        $available = $user && self::canReceive($user, $apprentice) && $user->email_verified_at
            && filter_var($user->email, FILTER_VALIDATE_EMAIL);
        $row = WeeklyRetentionReminder::whereKey($reminder->id)->whereIn('email_state', ['pending', 'held']);
        if (!$available) {
            $row->update(['email_state' => 'held']);
            return;
        }
        if (!$row->update(['email_state' => 'sending'])) {
            return;
        }

        try {
            $url = route('weekly-retention.reminders');
            $sent = Mail::raw("A weekly training reminder is available in the GTA portal. Sign in to see the details.\n\n".$url,
                fn (Message $message) => $message->to($user->email)->subject('GTA weekly training reminder'));
            $reminder->forceFill(['email_state' => $sent ? 'sent' : 'needs-check'])->save();
        } catch (\Throwable) {
            // SMTP may already have accepted this message. Reconcile transport logs before retrying.
            $reminder->forceFill(['email_state' => 'needs-check'])->save();
        }
    }
}
