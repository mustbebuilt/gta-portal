<?php

namespace App\Support;

use App\Models\AttendanceMark;
use App\Models\ExtraTraining;
use App\Models\TrainingSession;

class AttendanceLedger
{
    private const ROSTER_CHECK = 'Attendance totals are unavailable until saved source rosters have been checked by an administrator.';

    /** A damaged roster holds attendance only. Other reports retain the stricter ledger behaviour. */
    public static function reportPeriod(array $ids, string $from, string $to): ?array
    {
        try {
            return self::forIds($ids, false, $from, $to);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            if ($error->getStatusCode() !== 422 || $error->getMessage() !== self::ROSTER_CHECK) {
                throw $error;
            }

            return null;
        }
    }

    public static function forIds(array $ids, bool $includeOtj = true, ?string $from = null, ?string $through = null, ?string $code = null): array
    {
        if(!$ids){return [];}
        $rows = array_fill_keys($ids, []);
        $allowed = array_flip($ids);
        TrainingSession::whereDate('session_date', '<=', $through ?? today())
            ->when($from, fn ($query) => $query->whereDate('session_date', '>=', $from))
            ->when($code, fn ($query) => $query->whereIn('id', AttendanceMark::whereIn('apprentice_id', $ids)
                ->where('code', $code)->select('training_session_id')))
            ->orderBy('id')
            ->select(['id', 'name', 'session_date', 'start_time', 'end_time', 'state', 'members', 'delivered_learning'])
            ->chunkById(200, function ($sessions) use ($ids, $allowed, $code, &$rows): void {
                $sessions->load(['marks' => fn ($q) => $q
                    ->whereIn('apprentice_id', $ids)
                    ->select('id', 'training_session_id', 'apprentice_id', 'code', 'otj_confirmed', 'otj_minutes')]);
                foreach ($sessions as $s) {
                    if (! $s->ended()) {
                        continue;
                    }
                    $roster = RegisterFollowup::roster($s);
                    abort_unless($roster['shape'] && ! $roster['duplicates'], 422, self::ROSTER_CHECK);
                    $marks = $s->marks->keyBy('apprentice_id');
                    foreach ($s->members as $id) {
                        if (! isset($allowed[$id])) {
                            continue;
                        }
                        $m = $marks->get($id);
                        if ($code && $m?->code !== $code) {
                            continue;
                        }
                        $rows[$id][] = ['id' => $s->id, 'name' => $s->name, 'date' => $s->session_date->toDateString(), 'code' => $m?->code, 'minutes' => $s->state === 'Cancelled' ? 0 : ($m?->otj_confirmed ? $m->otj_minutes : null), 'cancelled' => $s->state === 'Cancelled', 'delivered' => $s->delivered_learning];
                    }
                }
            });
        $extras = ExtraTraining::whereIn('apprentice_id', $ids)->whereNull('withdrawn_at')->whereNotNull('approval')->get()->filter(fn ($e) => $e->occurred())->groupBy('apprentice_id');
        $historicalMinutes = [];
        if ($includeOtj) {
            $people = \App\Models\Apprentice::whereIn('id', $ids)->get();
            ReportRead85::run($people, function () use ($people, &$historicalMinutes): void {
                foreach ($people as $person) {
                    $historicalMinutes[$person->id] = array_sum(array_column(HistoricalOtj::ledger($person), 'minutes'));
                }
            });
        }
        $out = [];
        foreach ($rows as $id => $v) {
            usort($v, fn ($a, $b) => strcmp($a['date'], $b['date']));
            $report = AttendanceMath::report($v);
            $out[$id] = ['rows' => $v, 'report' => $report, 'extraMinutes' => $extras->get($id, collect())->sum('minutes'), 'historicalMinutes' => $includeOtj ? ($historicalMinutes[$id] ?? 0) : null];
        }

        return $out;
    }
}
