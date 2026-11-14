<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\AttendanceMark;
use App\Models\TrainingSession;

class StaffTrainingRecords
{
    public static function validDate($date): bool
    {
        return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)) && $date <= today()->toDateString();
    }

    // No all-cohort AttendanceLedger read: scope the saved roster before fetching marks.
    public static function attendance(Apprentice $a, string $from, string $to, bool $lock = false): array
    {
        $sessions = TrainingSession::where(fn ($q) => $q->whereJsonContains('members', $a->id)->orWhereJsonContains('members', (string) $a->id))->whereBetween('session_date', [$from, $to])->orderBy('session_date')->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $rows = [];
        foreach ($sessions as $s) {
            $roster = RegisterFollowup::roster($s);
            $marks = AttendanceMark::where('training_session_id', $s->id)->where('apprentice_id', $a->id)->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
            $valid = $roster['shape'] && ! $roster['duplicates'] && in_array($a->id, $roster['ids'], true) && $marks->count() <= 1;
            try {
                $ended = $s->ended();
            } catch (\Throwable) {
                $ended = false;
                $valid = false;
            }
            $m = $marks->first();
            $code = $valid && array_key_exists($m?->code ?? '', AttendanceMath::CODES) ? $m->code : null;
            $rows[] = ['id' => $s->id, 'version' => $s->version, 'name' => $s->name, 'date' => $s->session_date->toDateString(), 'start' => $s->start_time, 'end' => $s->end_time, 'state' => $s->state, 'ended' => $ended, 'valid' => $valid, 'mark_id' => $m?->id, 'mark_updated' => $m?->updated_at?->toISOString(), 'code' => $code];
        }

        return $rows;
    }

    public static function report(array $rows): array
    {
        $report = AttendanceMath::report(array_map(fn ($s) => ['date' => $s['date'], 'code' => $s['code'], 'minutes' => null, 'cancelled' => $s['state'] === 'Cancelled'], array_values(array_filter($rows, fn ($s) => $s['ended']))));
        $report['invalid_sources'] = count(array_filter($rows, fn ($s) => ! $s['valid']));
        if ($report['invalid_sources']) {
            $report['percent'] = null;
        }

        return $report;
    }
}
