<?php

namespace App\Support;

/** Pure projection. Caller MUST authorise apprentice and source records before use.
 * Inputs are normalised DTOs, not raw decrypted model arrays. No database writes or hours. */
final class TrainingCoverage
{
    public static function receipts(string $apprenticeId, array $sessions, string $today): array
    {
        $result = [];
        foreach ($sessions as $s) {
            if (($s['cancelled'] ?? false) || ! ($s['confirmed'] ?? false) || $s['date'] > $today) {
                continue;
            }
            // Reject stale structured records after an independently edited attendance register.
            if (($s['register_version'] ?? null) !== ($s['delivery_register_version'] ?? null)
                || ! isset($s['register_version'], $s['delivery_register_version'])) {
                continue;
            }
            $mark = null;
            foreach ($s['marks'] as $m) {
                if ((string) $m['id'] === $apprenticeId) {
                    $mark = $m;
                    break;
                }
            }
            if (! $mark || ! in_array($mark['code'], ['O', 'T'], true)) {
                continue;
            }
            foreach ($s['items'] as $item) {
                if (in_array($item['subjectId'], $mark['missed'] ?? [], true)) {
                    continue;
                }
                $result[] = array_merge($item, [
                    'coverage' => in_array($item['subjectId'], $mark['partial'] ?? [], true) ? 'partial' : $item['coverage'],
                    'date' => $s['date'],
                    'sessionId' => $s['id'],
                    'group' => $s['group'],
                    'tutor' => $s['tutor'],
                ]);
            }
        }
        usort($result, fn ($a, $b) => strcmp($a['date'], $b['date']) ?: strcmp((string) $a['sessionId'], (string) $b['sessionId']));

        return $result;
    }

    public static function journey(array $requirements, array $receipts, string $today): array
    {
        $result = [];
        foreach ($requirements as $r) {
            if (! ($r['required'] ?? false)) {
                continue;
            }
            $found = array_values(array_filter($receipts, fn ($x) => $x['subjectId'] === $r['subjectId']));
            $delivered = (bool) array_filter($found, fn ($x) => $x['coverage'] === 'delivered');
            $due = $r['due'] ?? null;
            $state = $delivered ? 'delivered' : ($due && $due < $today ? 'overdue' : ($found ? 'partial' : ($due === $today ? 'due' : ($due ? 'planned' : 'unscheduled'))));
            $result[] = array_merge($r, ['state' => $state, 'partial' => ! $delivered && (bool) $found, 'records' => $found]);
        }

        return $result;
    }
}
