<?php

namespace App\Support;

use Illuminate\Support\Collection;

final class ReportComparisons
{
    public static function charts(Collection $details, Collection $outcomes): array
    {
        $today = today()->toDateString();
        $through = today()->addDays(30)->toDateString();
        $reviewCounts = ['Overdue' => 0, 'Today to next 30 days' => 0, 'Due later' => 0, 'Needs checking' => 0];
        $reviewExcluded = 0;

        foreach ($details as $row) {
            $context = $outcomes->get($row['id'])?->context ?? [];
            $status = $context['status'] ?? null;
            $confirmed = filled($context['sourceRef'] ?? null);
            if (filled($context['epa_closure']['archived_at'] ?? null)
                || ($confirmed && in_array($status, ['Break in learning', 'Completed', 'Withdrawn', 'Transferred'], true))) {
                $reviewExcluded++;
                continue;
            }
            $due = $row['review_due'];
            $category = match (true) {
                !$confirmed || $status !== 'Active' || !$due => 'Needs checking',
                $due < $today => 'Overdue',
                $due <= $through => 'Today to next 30 days',
                default => 'Due later',
            };
            $reviewCounts[$category]++;
        }

        $attendanceRows = $details->groupBy('programme')->sortKeys()->map(function (Collection $people, string|int $name) {
            $attendance = $people->pluck('attendance');
            $unknown = $attendance->containsStrict(null);
            $expected = $unknown ? null : $attendance->sum('expected');
            $attended = $unknown ? null : $attendance->sum('attended');
            $unmarked = $unknown ? null : $attendance->sum('unmarked');

            return ['label' => (string) $name, 'value' => $expected && !$unmarked ? round(100 * $attended / $expected, 1) : null,
                'attended' => $attended, 'expected' => $expected, 'unmarked' => $unmarked];
        })->values()->all();

        $comparable = $details->filter(fn ($row) => $row['recorded_hours'] !== null
            && $row['progressive_expected_hours'] !== null && !$row['otj_pending'] && !$row['otj_checks']);
        $recorded = $comparable->isEmpty() ? null : round($comparable->sum('recorded_hours'), 2);
        $expected = $comparable->isEmpty() ? null : round($comparable->sum('progressive_expected_hours'), 2);
        $coverage = $comparable->count().' of '.$details->count().' apprentices have comparable figures.';
        $reviewNote = 'Uses checked review history and confirmed active programme status. Needs checking includes unknown status or due dates. '
            .$reviewExcluded.' paused, closed or archived record(s) excluded. Due dates are separate from bookings.';

        return [
            ['key' => 'review-due', 'title' => 'Progress reviews due', 'type' => 'bars', 'compact' => true,
                'unit' => 'apprentices', 'max' => max(1, array_sum($reviewCounts)), 'note' => $reviewNote,
                'rows' => PortalVisuals::rows($reviewCounts)],
            ['key' => 'attendance-by-programme', 'title' => 'Attendance by programme', 'type' => 'bars', 'compact' => true,
                'unit' => '% of expected sessions', 'max' => 100, 'note' => 'Uses the selected attendance period and counts attended sessions against expected sessions. Unmarked records or no expected sessions give an unknown rate. The chart shows the first 14 programmes. The table includes every programme.',
                'rows' => $attendanceRows],
            ['key' => 'otj-against-pace', 'title' => 'Off-the-job hours against target to date', 'type' => 'bars', 'compact' => true,
                'unit' => 'hours', 'max' => max(1, $recorded ?? 0, $expected ?? 0),
                'note' => $coverage.' Both totals use the same apprentices. Pending or invalid records and missing targets are excluded. Current position as of today.',
                'rows' => [['label' => 'Recorded', 'value' => $recorded], ['label' => 'Expected to date', 'value' => $expected, 'comparison' => true]]],
        ];
    }
}
