<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\LearningTask;
use App\Models\SmartAction;
use App\Models\User;

final class ReportHeadlines
{
    public static function for(User $user, array $reports): array
    {
        if (!isset($reports['dashboard.report'])) {
            return [];
        }

        $people = Apprentice::visibleTo($user)->select('id');
        if ($user->role === 'apprentice' && $people->count() !== 1) {
            return [];
        }

        $ids = $people->pluck('id')->all();
        $current = now('Europe/London')->startOfWeek();
        $from = $current->copy()->subWeeks(4)->toDateString();
        $to = $current->copy()->subDay()->toDateString();
        $source = AttendanceLedger::reportPeriod($ids, $from, $to);
        $attendance = $source === null ? null : AttendanceMath::report(collect($source)->pluck('rows')->collapse()->all());
        $attendanceUrl = route('visual-reports.index', ['from' => $from, 'to' => $to]);
        $waiting = LearningTask::whereIn('apprentice_id', $ids)->where('status', 'Awaiting marking')
            ->get(['id', 'status', 'submitted_at']);
        $operationsUrl = route('dashboard.report');

        $items = [
            self::item('Attendance', $attendance['percent'] ?? null, $attendanceUrl,
                $attendance === null ? 'Saved register rosters need checking'
                    : $attendance['attended'].' of '.$attendance['expected'].' expected sessions · '.$from.' to '.$to,
                'attendance', $attendance['expected'] ?? null),
            self::item('Awaiting marking', $waiting->count(), $operationsUrl.'#report-marking',
                'Current submitted tasks', null),
        ];
        if ($user->role !== 'apprentice') {
            $items[] = self::item('Apprentices in scope', count($ids), $operationsUrl, 'All authorised records, including historic records', null);
            $hasMissingDates = $waiting->contains(fn ($task) => !$task->submitted_at || $task->submitted_at->gt(now()));
            $items[] = self::item('Overdue marking', $hasMissingDates || !$ids ? null : $waiting->filter->markingOverdue()->count(),
                $operationsUrl.'#report-marking', $hasMissingDates ? 'Check submission dates' : 'More than seven calendar days', 'exceptions');
            $actions = SmartAction::whereIn('apprentice_id', $ids)->with('task')->get();
            $items[] = self::item('Overdue actions', $ids ? $actions->filter->overdue()->count() : null, $operationsUrl.'#report-actions',
                'Open actions past a recorded deadline', 'exceptions');
        }

        if (isset($reports['achievers-leavers.index'])) {
            $period = ReportOutcomes::defaultPeriod();
            $outcomes = ReportOutcomes::capture($period);
            $outcomesUrl = route('achievers-leavers.index', $period);
            $range = $period['from'].' to '.$period['to'];
            $items[] = self::item('Recorded achievers', $outcomes['achievers']->count(), $outcomesUrl, $range, null);
            $items[] = self::item('Recorded early leavers', $outcomes['earlyLeavers']->count(), $outcomesUrl, $range, null);
            $items[] = self::item('Outcome records to check', $outcomes['checks']->count(), $outcomesUrl,
                'Selected outcomes and records needing a date check', 'exceptions');
        }

        return $items;
    }

    private static function item(string $label, ?float $value, string $url, string $detail, ?string $measure, ?int $denominator = null): array
    {
        return [
            'name' => $label, 'value' => $value, 'href' => $url, 'detail' => $detail,
            'percentage' => $measure === 'attendance',
            ...ReportThresholds::internal($value, $measure, $denominator),
        ];
    }
}
