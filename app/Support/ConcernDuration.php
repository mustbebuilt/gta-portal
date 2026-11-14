<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Collection;

class ConcernDuration
{
    public static function summarise(Collection $cases): array
    {
        $ranges = [];
        $missing = 0;
        $invalid = 0;
        $open = 0;
        $asOf = today()->toDateString();
        foreach ($cases as $c) {
            if (! $c->acknowledged_at) {
                $missing++;

                continue;
            }
            $from = $c->acknowledged_at->toDateString();
            if ($c->status === 'Closed' && ! $c->closed_at) {
                $invalid++;

                continue;
            }
            $to = $c->status === 'Closed' ? $c->closed_at->toDateString() : $asOf;
            if ($from > $asOf || $to < $from || $to > $asOf) {
                $invalid++;

                continue;
            }
            if ($c->status !== 'Closed') {
                $open++;
            }$ranges[] = [$from, $to];
        }
        usort($ranges, fn ($a, $b) => strcmp($a[0], $b[0]));
        $merged = [];
        foreach ($ranges as $v) {
            $i = count($merged) - 1;
            if ($i >= 0 && $v[0] <= $merged[$i][1]) {
                $merged[$i][1] = max($merged[$i][1], $v[1]);
            } else {
                $merged[] = $v;
            }
        }
        $days = 0;
        foreach ($merged as [$from,$to]) {
            $days += (int) Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        }

        return ['days' => $ranges ? $days : null, 'recorded_intervals' => count($ranges), 'unacknowledged' => $missing, 'date_checks' => $invalid, 'open' => $open, 'as_of' => $asOf];
    }
}
