<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\ExtraTraining;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;

class OtjPosition
{
    public static function forApprentice(Apprentice $a): array
    {
        $rows = [];
        $pending = 0;
        $invalid = 0;
        $sessions = TrainingSession::with(['marks' => fn ($q) => $q->where('apprentice_id', $a->id)])->whereJsonContains('members', $a->id)->orderBy('session_date')->orderBy('id')->get();
        foreach ($sessions as $s) {
            if (! $s->ended()) {
                continue;
            }
            $mark = $s->marks->first();
            $minutes = 0;
            $state = 'Excluded';
            if ($s->state === 'Cancelled') {
                $state = 'Cancelled';
            } elseif (! $mark || ! $mark->code) {
                $state = 'Register not marked';
                $pending++;
            } elseif (in_array($mark->code, ['O', 'T'], true)) {
                if (! $mark->otj_confirmed) {
                    $state = 'OTJ not confirmed';
                    $pending++;
                } elseif ($mark->otj_minutes === null || $mark->otj_minutes < 0) {
                    $state = 'Minutes need checking';
                    $invalid++;
                } else {
                    $minutes = (int) $mark->otj_minutes;
                    $state = 'Confirmed';
                }
            }
            $rows[] = ['kind' => 'session', 'id' => $s->id, 'date' => $s->session_date->toDateString(), 'title' => $s->name, 'minutes' => $minutes, 'state' => $state, 'code' => $mark?->code, 'source_version' => $s->version];
        }
        foreach (ReportRead85::rows(ExtraTraining::class, $a->id, fn () => ExtraTraining::where('apprentice_id', $a->id)->orderBy('training_date')->orderBy('id')->get())->sortBy(fn ($row) => [$row->training_date->toDateString(), $row->id]) as $e) {
            if (! $e->occurred()) {
                continue;
            }
            $included = ! $e->withdrawn_at && $e->approval && $e->minutes > 0;
            $state = $e->withdrawn_at ? 'Withdrawn' : ($included ? 'Approved extra training' : 'Awaiting approval / check');
            if (! $included && ! $e->withdrawn_at) {
                $pending++;
            }
            $rows[] = ['kind' => 'extra', 'id' => $e->id, 'date' => $e->training_date->toDateString(), 'title' => $e->topic, 'minutes' => $included ? (int) $e->minutes : 0, 'state' => $state, 'code' => null, 'source_version' => $e->version];
        }
        $historical = HistoricalOtj::ledger($a);
        $invalid += count(array_filter($historical, fn ($r) => $r['data_check']));
        $rows = [...$rows, ...$historical];
        usort($rows, fn ($x, $y) => [$x['date'], $x['kind'], $x['id']] <=> [$y['date'], $y['kind'], $y['id']]);
        $cumulative = 0;
        foreach ($rows as &$row) {
            $cumulative += $row['minutes'];
            $row['cumulative_minutes'] = $cumulative;
        }
        unset($row);
        $plan = DeliveryPlan::approved($a);
        $target = $plan ? (float) $plan['details']['target_hours'] : null;
        // No daily interpolation: planned hours become due only after a whole block has ended.
        $due = $plan ? array_sum(array_column(array_filter($plan['blocks'], fn ($b) => $b['number'] < 12 && $b['end'] < today()->toDateString()), 'hours')) : null;
        $summary = ['captured_at' => now()->toIso8601String(), 'date' => today()->toDateString(), 'session_minutes' => array_sum(array_column(array_filter($rows, fn ($r) => $r['kind'] === 'session'), 'minutes')), 'historical_minutes' => array_sum(array_column($historical, 'minutes')), 'extra_minutes' => array_sum(array_column(array_filter($rows, fn ($r) => $r['kind'] === 'extra'), 'minutes')), 'recorded_minutes' => $cumulative, 'pending_sources' => $pending, 'invalid_sources' => $invalid, 'target_hours' => $target, 'plan_hash' => $plan['hash'] ?? null, 'plan_source' => $plan['details']['source_ref'] ?? null, 'planned_due_hours' => $due, 'recorded_percent' => $target > 0 ? $cumulative / 60 / $target * 100 : null, 'loaded_record_gap_hours' => $due !== null ? max(0, $due - $cumulative / 60) : null, 'historical_completeness' => 'Not verified; loaded source records only', 'pace_basis' => 'Planned hours in blocks ending before today; no assumed daily distribution or historic target reconstruction'];

        $expected = self::progressiveHours($plan, today()->toDateString());
        $summary['progressive_expected_hours'] = $expected;
        $summary['progressive_gap_hours'] = $expected === null ? null : round(max(0, $expected - $cumulative / 60), 2);
        $summary['progressive_basis'] = 'Indicative pace by elapsed calendar days within each dated training block. Includes time up to the start of today.';

        return ['summary' => $summary, 'rows' => $rows];
    }

    public static function progressiveHours(?array $plan, string $date): ?float
    {
        if ($plan === null) {
            return null;
        }

        $asOf = CarbonImmutable::parse($date);
        $hours = 0.0;
        $scheduled = 0.0;
        foreach ($plan['blocks'] as $block) {
            if ($block['number'] >= 12) {
                continue;
            }
            if (! is_numeric($block['hours'] ?? null) || (float) $block['hours'] < 0) {
                return null;
            }
            $blockHours = (float) $block['hours'];
            if ($blockHours === 0.0) {
                continue;
            }
            if (! filled($block['start'] ?? null) || ! filled($block['end'] ?? null) || $block['end'] < $block['start']) {
                return null;
            }

            $start = CarbonImmutable::parse($block['start']);
            $end = CarbonImmutable::parse($block['end'])->addDay();
            $elapsed = $start->diffInDays($asOf, false);
            $fraction = min(1, max(0, $elapsed / $start->diffInDays($end)));
            $scheduled += $blockHours;
            $hours += $blockHours * $fraction;
        }

        if (! is_numeric($plan['details']['target_hours'] ?? null)
            || abs($scheduled - (float) $plan['details']['target_hours']) > 0.001) {
            return null;
        }

        return round($hours, 2);
    }
}
