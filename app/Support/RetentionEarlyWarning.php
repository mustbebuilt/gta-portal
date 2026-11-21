<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class RetentionEarlyWarning
{
    public static function evidence(Collection $checkIns, array $registers, array $markerSummary, ?string $start, ?string $through): array
    {
        $latest = null;
        $months = 0;
        $areas = [];
        $pair = null;

        if ($through !== null) {
            $asOf = CarbonImmutable::parse($through);
            $window = $asOf->subDays(59)->toDateString();
            $from = $start === null ? $window : max($start, $window);
            $sessions = collect($registers)->keyBy('id');
            foreach ($checkIns as $row) {
                $snapshots = [MonthlyDevelopment::read($row), ...array_column(array_reverse($row->history ?? []), 'before_development')];
                $snapshot = collect($snapshots)->first(function ($snapshot) use ($sessions, $row, $from, $through) {
                    if (($snapshot['schema'] ?? null) !== 'gta-development-v2') {
                        return false;
                    }
                    $saved = substr($snapshot['at'] ?? '', 0, 10);
                    $session = $sessions->get($snapshot['session_id'] ?? null);
                    return CohortInsights::validDate($saved) && $saved <= $through && $session
                        && ! $session['cancelled'] && in_array($session['code'], ['O', 'T'], true)
                        && $saved >= $session['date'] && substr($session['date'], 0, 7) === $row->month
                        && $session['date'] >= $from && $session['date'] <= $through;
                });
                if (! $snapshot) {
                    continue;
                }
                $months++;
                $latest ??= ['month' => $row->month, 'session_id' => $snapshot['session_id']];
                foreach ($snapshot['ratings'] as $area => $rating) {
                    if (isset(ApprenticeReviewData::DEVELOPMENT_AREAS[$area]) && in_array($rating, ApprenticeReviewData::RATINGS, true) && $rating !== 'Not observed') {
                        $areas[$area] ??= ['rating' => $rating, 'month' => $row->month, 'session_id' => $snapshot['session_id'], 'support_months' => 0];
                        $areas[$area]['support_months'] += (int) ($rating === 'Needs support');
                    }
                }
            }

            $attendanceFrom = max($from, $asOf->subWeeks(6)->startOfWeek()->toDateString());
            $weekStart = $asOf->startOfWeek()->toDateString();
            $completedWeeks = array_filter($registers, fn ($row) => $row['date'] >= $attendanceFrom && $row['date'] < $weekStart);
            $pair = collect(AttendanceMath::report($completedWeeks)['pairs'])->last();
            if ($pair && collect($registers)->contains(fn ($row) => $row['date'] > $pair['to']
                && $row['date'] <= $through && ! $row['cancelled'] && in_array($row['code'], ['O', 'T'], true))) {
                $pair = null;
            }
        }

        $needsSupport = array_filter($areas, fn ($area) => $area['rating'] === 'Needs support');
        $markers = $through === null ? [] : $markerSummary['active_retention'];

        return [
            'latest' => $latest, 'months' => $months, 'areas' => $areas,
            'needs_support' => $needsSupport, 'attendance' => $pair, 'markers' => $markers,
            'signal_count' => (int) (bool) $needsSupport + (int) ($pair !== null) + (int) (bool) $markers,
        ];
    }

    public static function forReport(array $rows): array
    {
        $people = collect($rows);
        $early = $people->where('withdrawal_timing', 'Early leaver');
        $achieved = $people->where('sourced_achievement', 'Achieved');
        $active = $people->where('sourced_status', 'Active');
        $warnings = $active->filter(fn ($row) => $row['early_warning']['signal_count'] > 0)
            ->sortByDesc(fn ($row) => $row['early_warning']['signal_count']);

        $outcomes = [];
        foreach (['Early leavers' => $early, 'Achievers' => $achieved] as $outcome => $group) {
            $evidence = $group->pluck('early_warning');
            $outcomes[$outcome] = [
                'total' => $group->count(),
                'check_ins' => $evidence->where('months', '>', 0)->count(),
                'attendance' => $evidence->filter(fn ($row) => $row['attendance'] !== null)->count(),
                'markers' => $evidence->filter(fn ($row) => $row['markers'] !== [])->count(),
                'areas' => collect(ApprenticeReviewData::DEVELOPMENT_AREAS)->map(function ($label, $key) use ($evidence) {
                    $observed = $evidence->pluck('areas.'.$key)->filter();
                    return [
                        'observed' => $observed->count(),
                        'needs_support' => $observed->where('rating', 'Needs support')->count(),
                        'consistent' => $observed->where('rating', 'Consistently demonstrated')->count(),
                    ];
                }),
            ];
        }

        return [
            'outcomes' => $outcomes,
            'leaving_reasons' => $early->countBy('reason')->sortDesc(),
            'active' => $active->count(),
            'active_with_check_ins' => $active->where('early_warning.months', '>', 0)->count(),
            'status_checks' => $people->where('sourced_status', 'Unknown')->count(),
            'warnings' => $warnings,
        ];
    }
}
