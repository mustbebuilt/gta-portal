<?php

namespace App\Support;

/** Pure review-data functions. Call only with authorised, normalised source records. */
final class ApprenticeReviewData
{
    public const DEVELOPMENT_AREAS = [
        'engagement' => 'Attitude and engagement',
        'application' => 'Application and effort',
        'professional' => 'Professional behaviour',
        'respect' => 'Respect and teamwork',
        'independence' => 'Independence and initiative',
        'safety' => 'Safe working',
    ];

    public const RATINGS = ['Needs support', 'Developing', 'Consistently demonstrated', 'Not observed'];

    public static function percentage(?int $done, ?int $total): ?float
    {
        if ($done === null || $total === null || $total <= 0 || $done < 0 || $done > $total) {
            return null;
        }

        return round(100 * $done / $total, 1);
    }

    public static function progress(array $completedIds, array $assignedIds, ?array $personalProgrammeIds): array
    {
        $done = array_unique($completedIds);
        $assigned = array_unique($assignedIds);
        $overall = $personalProgrammeIds === null ? null : array_unique($personalProgrammeIds);
        $assignedDone = count(array_intersect($done, $assigned));
        $overallDone = $overall === null ? null : count(array_intersect($done, $overall));

        return [
            'assigned_done' => $assignedDone,
            'assigned_total' => count($assigned),
            'assigned_percent' => self::percentage($assignedDone, count($assigned)),
            'overall_done' => $overallDone,
            'overall_total' => $overall === null ? null : count($overall),
            'overall_percent' => self::percentage($overallDone, $overall === null ? null : count($overall)),
        ];
    }

    public static function date(string $date): \DateTimeImmutable
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Europe/London'));
        if (! $d || $d->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Invalid calendar date.');
        }

        return $d;
    }

    public static function period(?string $lastMeeting, string $start, string $asOf, ?string $meeting = null): array
    {
        $first = self::date($start);
        $end = self::date($meeting ?? $asOf);
        $as = self::date($asOf);
        if ($end > $as || $end < $first) {
            throw new \InvalidArgumentException('Review date is outside the recorded learning period.');
        }
        $last = $lastMeeting === null ? null : self::date($lastMeeting);
        if ($last && ($last < $first || $last > $end)) {
            throw new \InvalidArgumentException('Check the previous meeting date.');
        }

        return [
            'from' => ($last ?? $first)->format('Y-m-d'),
            'from_exclusive' => $last !== null,
            'to' => $end->format('Y-m-d'),
            'scheduled_due' => ($last ?? $first)->modify('+70 days')->format('Y-m-d'),
            'next_due' => $meeting === null ? null : $end->modify('+70 days')->format('Y-m-d'),
            'target_window_end' => $end->modify('+70 days')->format('Y-m-d'),
            'draft_only' => $meeting === null,
        ];
    }

    public static function tasks(array $tasks, string $asOf, string $windowEnd): array
    {
        $date = self::date($asOf);
        $end = self::date($windowEnd);
        $out = ['overdue' => [], 'due_before_next' => [], 'undated' => []];
        $seen = [];
        foreach ($tasks as $t) {
            $id = (string) $t['id'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            if (($t['approved'] ?? false) || ($t['source_pending'] ?? false)) {
                continue;
            }
            if (empty($t['due'])) {
                $out['undated'][] = $t;
                continue;
            }
            $due = self::date($t['due']);
            if ($due < $date) {
                $out['overdue'][] = $t;
            } elseif ($due <= $end) {
                $out['due_before_next'][] = $t;
            }
        }
        foreach ($out as &$rows) {
            usort($rows, fn ($a, $b) => strcmp($a['due'] ?? '9999', $b['due'] ?? '9999') ?: strcmp((string) $a['id'], (string) $b['id']));
        }

        return $out;
    }

    public static function delivered(array $receipts, array $period): array
    {
        $out = ['training' => [], 'fs' => [], 'themes' => []];
        $seen = [];
        foreach ($receipts as $r) {
            $date = $r['date'];
            self::date($date);
            $after = $period['from_exclusive'] ? $date > $period['from'] : $date >= $period['from'];
            if (! $after || $date > $period['to'] || ! ($r['attended'] ?? false) || ($r['cancelled'] ?? false) || ! ($r['valid'] ?? false)) {
                continue;
            }
            $key = $r['session_id'].'|'.$r['subject_id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if (isset($out[$r['area']])) {
                $out[$r['area']][] = $r;
            }
        }

        return $out;
    }

    public static function development(array $ratings, string $comment): array
    {
        if (array_diff(array_keys($ratings), array_keys(self::DEVELOPMENT_AREAS))) {
            throw new \InvalidArgumentException('Unknown development area.');
        }
        foreach (self::DEVELOPMENT_AREAS as $key => $label) {
            if (! array_key_exists($key, $ratings) || ! in_array($ratings[$key], self::RATINGS, true)) {
                throw new \InvalidArgumentException('Choose a rating or Not observed for each area.');
            }
        }
        if (mb_strlen($comment) > 1000) {
            throw new \InvalidArgumentException('Keep the comment within 1000 characters.');
        }

        return ['schema' => 'gta-development-v2', 'ratings' => $ratings, 'comment' => trim($comment)];
    }

    public static function months(string $from, string $to, array $records): array
    {
        $a = self::date($from)->modify('first day of this month');
        $b = self::date($to)->modify('first day of this month');
        $out = [];
        for ($d = $a; $d <= $b; $d = $d->modify('+1 month')) {
            $month = $d->format('Y-m');
            $r = $records[$month] ?? null;
            $out[] = ['month' => $month, 'state' => $r === null ? 'Not recorded' : (($r['schema'] ?? null) === 'gta-development-v2' ? 'Recorded' : 'Legacy scale'), 'record' => $r];
        }

        return $out;
    }

    public static function safety(?bool $answer, ?string $followup): void
    {
        if ($answer === null) {
            throw new \InvalidArgumentException('Answer the accidents or near-misses question.');
        }
        if ($answer && trim($followup ?? '') === '') {
            throw new \InvalidArgumentException('Record the safe follow-up or restricted incident reference.');
        }
    }
}
