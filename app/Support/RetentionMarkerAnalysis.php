<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class RetentionMarkerAnalysis
{
    private const RETENTION_CODES = ['IM34', 'IM35', 'IM36', 'IM37', 'IM38', 'IM39', 'IM40'];

    public static function summarise(Collection $current, Collection $history, string $cutoff): array
    {
        $first = $history->first();
        $baseline = $first ? self::codes($first->before_codes) : $current->pluck('code')->all();
        $baselineDate = $first ? self::day($first->created_at) : null;
        $currentDates = $current->mapWithKeys(fn ($row) => [$row->code => self::day($row->created_at)]);
        $changes = [];
        $incomplete = false;

        foreach ($baseline as $code) {
            $start = $currentDates[$code] ?? null;
            if (! $start || ($baselineDate && $start > $baselineDate)) {
                $start = $baselineDate;
                if ($start && $start <= $cutoff && in_array($code, self::RETENTION_CODES, true)) {
                    $incomplete = true;
                }
            }
            if ($start && $start <= $cutoff) {
                $changes[] = ['date' => $start, 'add' => [$code], 'remove' => [], 'record' => false];
            }
        }
        foreach ($history as $event) {
            $date = self::day($event->created_at);
            if (! $date || $date > $cutoff) {
                continue;
            }
            $before = self::codes($event->before_codes);
            $after = self::codes($event->after_codes);
            $changes[] = [
                'date' => $date, 'add' => array_diff($after, $before),
                'remove' => array_diff($before, $after), 'record' => true,
            ];
        }

        // Stable sort keeps same-day snapshots in their original order.
        usort($changes, fn ($left, $right) => $left['date'] <=> $right['date']);

        $active = [];
        $recordsByCode = [];
        $markers = [];
        $retentionStart = null;
        $retentionOccasions = $retentionDays = $records = $retentionRecords = 0;
        foreach ($changes as $change) {
            $date = $change['date'];
            $touched = array_merge($change['add'], $change['remove']);
            if ($change['record']) {
                $records++;
                foreach ($touched as $code) {
                    $recordsByCode[$code] = ($recordsByCode[$code] ?? 0) + 1;
                }
                $retentionRecords += (int) (bool) array_intersect(self::RETENTION_CODES, $touched);
            }
            foreach ($change['remove'] as $code) {
                if (isset($active[$code])) {
                    $markers[$code]['days'] += self::days($active[$code], $date);
                    unset($active[$code]);
                }
            }
            foreach ($change['add'] as $code) {
                if (isset($active[$code])) {
                    continue;
                }
                $markers[$code] ??= ['occasions' => 0, 'days' => 0];
                $markers[$code]['occasions']++;
                $active[$code] = $date;
            }

            $retentionActive = (bool) array_intersect(self::RETENTION_CODES, array_keys($active));
            if ($retentionActive && $retentionStart === null) {
                $retentionStart = $date;
                $retentionOccasions++;
            } elseif (! $retentionActive && $retentionStart !== null) {
                $retentionDays += self::days($retentionStart, $date);
                $retentionStart = null;
            }
        }

        $afterCutoff = CarbonImmutable::parse($cutoff)->addDay()->toDateString();
        foreach ($active as $code => $start) {
            $markers[$code]['days'] += self::days($start, $afterCutoff);
        }
        if ($retentionStart !== null) {
            $retentionDays += self::days($retentionStart, $afterCutoff);
        }

        foreach ($markers as $code => &$marker) {
            $marker['records'] = $recordsByCode[$code] ?? 0;
        }
        unset($marker);
        uksort($markers, 'strnatcmp');
        return [
            'markers' => $markers,
            'active_retention' => array_values(array_intersect(self::RETENTION_CODES, array_keys($active))),
            'retention_count' => count(array_intersect(self::RETENTION_CODES, array_keys($markers))),
            'retention_occasions' => $retentionOccasions,
            'retention_days' => $retentionDays,
            'records' => $records,
            'retention_records' => $retentionRecords,
            'incomplete' => $incomplete,
            'as_of' => $cutoff,
        ];
    }

    public static function trends(array $rows, array $labels): array
    {
        $trends = [];
        foreach ($rows as $row) {
            $early = $row['withdrawal_timing'] === 'Early leaver';
            $knownOutcome = $row['sourced_status'] === 'Completed'
                || $early || $row['withdrawal_timing'] === 'Withdrawn on or after planned end';
            foreach ($row['marker_summary']['markers'] as $code => $marker) {
                $trends[$code] ??= [
                    'label' => $labels[$code] ?? $code, 'people' => 0,
                    'occasions' => 0, 'days' => 0, 'records' => 0, 'early_leavers' => 0, 'known_outcomes' => 0,
                ];
                $trends[$code]['people']++;
                $trends[$code]['occasions'] += $marker['occasions'];
                $trends[$code]['records'] += $marker['records'];
                $trends[$code]['days'] += $marker['days'];
                $trends[$code]['early_leavers'] += (int) $early;
                $trends[$code]['known_outcomes'] += (int) $knownOutcome;
            }
        }
        uksort($trends, 'strnatcmp');

        return $trends;
    }

    private static function codes(string $json): array
    {
        return array_unique(json_decode($json, true) ?? []);
    }

    private static function day(?string $timestamp): ?string
    {
        $date = substr($timestamp ?? '', 0, 10);

        return CohortInsights::validDate($date) ? $date : null;
    }

    private static function days(string $from, string $until): int
    {
        return (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($until));
    }
}
