<?php

namespace App\Support;

use DateTimeImmutable;
use InvalidArgumentException;

final class RplPlanDates69
{
    public static function policy(array $template): ?array
    {
        $key = strtoupper($template['standard_code']).':'.ltrim(strtolower($template['standard_version']), 'v');

        return config('gta_rpl_dates', [])[$key] ?? null;
    }

    public static function calculate(array $policy, string $start, float $hours, array $record = []): array
    {
        $startDate = self::date($start);
        $annualHours = $policy['hours_per_training_week'] * $policy['training_weeks_per_year'];
        if ($hours <= 0 || $annualHours <= 0 || $policy['epa_months'] < 1) {
            throw new InvalidArgumentException('Check the approved training hours and calendar rules.');
        }

        // Excel ROUNDUP followed by an inclusive day count. Correct floating point
        // noise at integer boundaries without rounding the OTJ hours for display.
        $rawDays = $hours * 365 / $annualHours;
        $wholeDays = max(1, (int) ceil(round($rawDays, 10)));
        $practical = $startDate->modify('+'.($wholeDays - 1).' days');

        $pauseStart = $record['pause_start'] ?? null;
        $pauseEnd = $record['pause_end'] ?? null;
        if ($pauseStart !== null || $pauseEnd !== null) {
            $first = self::date($pauseStart ?? '');
            $last = self::date($pauseEnd ?? '');
            if ($first < $startDate || $first > $practical || $last < $first) {
                throw new InvalidArgumentException('Check that the agreed pause starts within the practical period and ends on or after its start.');
            }
            $practical = $practical->modify('+'.($first->diff($last)->days + 1).' days');
        }

        // Match Excel EDATE, including month-end clamping.
        $month = $practical->modify('first day of this month')->modify('+'.$policy['epa_months'].' months');
        $end = $month->setDate((int) $month->format('Y'), (int) $month->format('n'),
            min((int) $practical->format('j'), (int) $month->format('t')));

        return ['practical_end' => $practical->format('Y-m-d'), 'programme_end' => $end->format('Y-m-d')];
    }

    public static function schedule(array $blocks, string $start, array $dates, ?string $entered): array
    {
        $lines = $entered === null || trim($entered) === '' ? [] : preg_split('/\R/', trim($entered));
        if ($lines && count($lines) !== count($blocks)) {
            throw new InvalidArgumentException('Enter one start and end date for each of the 12 approved blocks.');
        }

        $startDate = self::date($start);
        $previous = null;
        $result = [];
        foreach (array_values($blocks) as $index => $block) {
            if ($block['number'] !== $index + 1) {
                throw new InvalidArgumentException('The controlled template must list blocks in teaching order.');
            }
            if ($lines) {
                $parts = array_map('trim', explode(',', $lines[$index]));
                if (count($parts) !== 2) {
                    throw new InvalidArgumentException('Enter each approved block as two dates separated by a comma.');
                }
                [$from, $to] = $parts;
            } else {
                $from = $startDate->modify('+'.$block['start_day'].' days')->format('Y-m-d');
                $to = $startDate->modify('+'.$block['end_day'].' days')->format('Y-m-d');
            }

            $first = self::date($from);
            $last = self::date($to);
            if ($first < $startDate || $first > $last || ($previous && $first <= $previous)
                || ($block['number'] < 12 && $to > $dates['practical_end'])
                || ($block['number'] === 11 && $to !== $dates['practical_end'])
                || ($block['number'] === 12 && $to !== $dates['programme_end'])) {
                throw new InvalidArgumentException('Enter approved block dates in order. Block 11 must end at the practical end and block 12 at the apprenticeship end.');
            }
            $previous = $last;
            $result[] = ['number' => $block['number'], 'title' => $block['title'],
                'hours' => (string) $block['hours'], 'start' => $from, 'end' => $to];
        }

        return $result;
    }

    public static function binding(array $programme, array $rpl, array $policy): string
    {
        return Rpl68::hash([
            $programme['template_id'], $programme['template_hash'], $programme['start_date'],
            $rpl['mode'] ?? null, $rpl['adjustments'] ?? [], $policy,
        ]);
    }

    public static function review(array $template, array $programme, array $rpl, ?array $calculation, array $record): array
    {
        $policy = self::policy($template);
        $start = $programme['start_date'] ?? null;
        if (!$policy || !$start || !$calculation || $calculation['revised_hours_unrounded'] <= 0) {
            return [];
        }

        $proposed = null;
        try {
            $proposed = self::calculate($policy, $start, $calculation['revised_hours_unrounded'], $record);
            if (($record['binding'] ?? null) !== self::binding($programme, $rpl, $policy)) {
                throw new InvalidArgumentException('Check the dates against the agreed Training Plan and save its reference. If the start, RPL or template changed, confirm the dates again.');
            }
            if (($record['practical_end'] ?? null) !== $proposed['practical_end'] || ($record['programme_end'] ?? null) !== $proposed['programme_end']) {
                throw new InvalidArgumentException('The calculated dates differ from the agreed Training Plan. Reconcile the inputs before requesting approval.');
            }
            if (!isset($record['agreed_hours']) || round((float) $record['agreed_hours'], 2) !== round($calculation['revised_hours_unrounded'], 2)) {
                throw new InvalidArgumentException('The revised OTJ hours differ from the agreed Training Plan. Reconcile the hours before requesting approval.');
            }
            $schedule = self::schedule($calculation['blocks'], $start, $proposed, $record['schedule'] ?? null);

            return ['proposed' => $proposed, 'approved' => [
                ...$proposed, 'source' => $record['source'], 'blocks' => $schedule,
            ], 'issue' => null];
        } catch (InvalidArgumentException $e) {
            return ['proposed' => $proposed, 'approved' => null, 'issue' => $e->getMessage()];
        }
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Use valid dates in YYYY-MM-DD format.');
        }

        return $date;
    }
}
