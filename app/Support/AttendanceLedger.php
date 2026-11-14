<?php

namespace App\Support;

use App\Models\ExtraTraining;
use App\Models\TrainingSession;

class AttendanceLedger
{
    public static function forIds(array $ids): array
    {
        $rows = array_fill_keys($ids, []);
        $allowed = array_flip($ids);
        $sessions = TrainingSession::with(['marks' => fn ($q) => $q->whereIn('apprentice_id', $ids)])->whereDate('session_date', '<=', today())->get();
        foreach ($sessions as $s) {
            if (! $s->ended()) {
                continue;
            }$marks = $s->marks->keyBy('apprentice_id');
            foreach ($s->members as $id) {
                if (! isset($allowed[$id])) {
                    continue;
                }$m = $marks->get($id);
                $rows[$id][] = ['id' => $s->id, 'name' => $s->name, 'date' => $s->session_date->toDateString(), 'code' => $m?->code, 'minutes' => $s->state === 'Cancelled' ? 0 : ($m?->otj_confirmed ? $m->otj_minutes : null), 'cancelled' => $s->state === 'Cancelled', 'delivered' => $s->delivered_learning];
            }
        }
        $extras = ExtraTraining::whereIn('apprentice_id', $ids)->whereNull('withdrawn_at')->whereNotNull('approval')->get()->filter(fn ($e) => $e->occurred())->groupBy('apprentice_id');
        $out = [];
        foreach ($rows as $id => $v) {
            usort($v, fn ($a, $b) => strcmp($a['date'], $b['date']));
            $report = AttendanceMath::report($v);
            $out[$id] = ['rows' => $v, 'report' => $report, 'extraMinutes' => $extras->get($id, collect())->sum('minutes'), 'historicalMinutes'=>array_sum(array_column(HistoricalOtj::ledger(\App\Models\Apprentice::findOrFail($id)),'minutes'))];
        }

        return $out;
    }
}
