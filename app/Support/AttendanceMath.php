<?php

namespace App\Support;

use Carbon\Carbon;

class AttendanceMath
{
    public const CODES = ['O' => 'Attended', 'A' => 'Absent', 'H' => 'Approved Holiday', 'S' => 'Sickness', 'T' => 'GTA technical course', 'W' => 'Kept at work', 'E' => 'Awaiting EPA test or result', 'C' => 'GTA Closed', 'B' => 'Break in learning'];

    public static function time(string $s): int
    {
        $p = explode(':', $s);

        return (int) $p[0] * 60 + (int) $p[1];
    }

    public static function parts(string $arrival, string $departure, string $start, string $end, array $breaks): array
    {
        $a = max(self::time($arrival), self::time($start));
        $b = min(self::time($departure), self::time($end));
        if ($b <= $a) {
            return [];
        }$parts = [[$a, $b]];
        foreach ($breaks as $break) {
            $x = self::time($break['start']);
            $y = self::time($break['end']);
            $next = [];
            foreach ($parts as [$s,$e]) {
                if ($y <= $s || $x >= $e) {
                    $next[] = [$s, $e];
                } else {
                    if ($x > $s) {
                        $next[] = [$s, min($x, $e)];
                    }if ($y < $e) {
                        $next[] = [max($y, $s), $e];
                    }
                }
            }$parts = $next;
        }

return $parts;
    }

    public static function overlap(array $a, array $b): bool
    {
        foreach ($a as [$x,$y]) {
            foreach ($b as [$u,$v]) {
                if (max($x, $u) < min($y, $v)) {
                    return true;
                }
            }
        }

return false;
    }

    public static function report(array $rows): array
    {
        $out = ['attended' => 0, 'missed' => 0, 'excluded' => 0, 'unmarked' => 0, 'cancelled' => 0, 'minutes' => 0, 'percent' => null, 'pairs' => []];
        $weeks = [];
        foreach ($rows as $row) {
            if ($row['cancelled']) {
                $out['cancelled']++;

                continue;
            }$code = $row['code'];
            $bucket = in_array($code, ['O', 'T'], true) ? 'attended' : (in_array($code, ['A', 'H', 'S', 'W'], true) ? 'missed' : (in_array($code, ['C', 'B', 'E'], true) ? 'excluded' : 'unmarked'));
            $out[$bucket]++;
            $out['minutes'] += $row['minutes'] ?? 0;
            $week = Carbon::parse($row['date'])->startOfWeek()->toDateString();
            $weeks[$week] ??= ['attended' => 0, 'missed' => 0, 'unmarked' => 0, 'excluded' => 0];
            $weeks[$week][$bucket]++;
        }
        $out['expected'] = $out['attended'] + $out['missed'] + $out['unmarked'];
        if (! $out['unmarked'] && $out['expected']) {
            $out['percent'] = 100 * $out['attended'] / $out['expected'];
        }
        ksort($weeks);
        $keys = array_keys($weeks);
        for ($i = 1; $i < count($keys); $i++) {
            $a = $keys[$i - 1];
            $b = $keys[$i];
            $one = $weeks[$a];
            $two = $weeks[$b];
            if (Carbon::parse($a)->addDays(7)->toDateString() === $b && Carbon::parse($b)->addDays(6)->lt(today()) && $one['missed'] > 0 && $two['missed'] > 0 && ! $one['attended'] && ! $two['attended'] && ! $one['unmarked'] && ! $two['unmarked']) {
                $out['pairs'][] = ['from' => $a, 'to' => Carbon::parse($b)->addDays(6)->toDateString()];
            }
        }

        return $out;
    }
}
