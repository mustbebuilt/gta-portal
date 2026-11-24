<?php

namespace App\Support;

use App\Models\ApprenticeEntryContext;
use Illuminate\Support\Collection;

final class EmploymentAtSession
{
    public static function timeline(?ApprenticeEntryContext $entry, Collection $employment): array
    {
        $points = [];
        if ($entry?->stage === 'Enrolment' && $entry->confirmed_on && $entry->entry_employer_id
            && $entry->employment_start_date && $entry->employment_start_date->lte($entry->confirmed_on)) {
            $points[$entry->employment_start_date->toDateString()][] = (int) $entry->entry_employer_id;
        }

        foreach ($employment as $event) {
            $date = $event->effective_on?->toDateString();
            if ($date) {
                $id = $event->payload['after']['entry_employer_id'] ?? null;
                $points[$date][] = $id ? (int) $id : null;
            }
        }

        ksort($points);
        return array_map(function (array $ids): ?int {
            $agreed = array_unique($ids, SORT_REGULAR);
            return count($agreed) === 1 ? reset($agreed) : null;
        }, $points);
    }

    public static function on(string $date, array $timeline, Collection $assignments, ?int $current): ?int
    {
        $previous = array_filter($timeline, fn ($on) => $on <= $date, ARRAY_FILTER_USE_KEY);
        $from = array_key_last($previous);
        $employer = $previous[$from] ?? null;
        if (!$employer) {
            return null;
        }

        $later = array_filter($timeline, fn ($on) => $on > $date, ARRAY_FILTER_USE_KEY);
        $until = array_key_first($later);
        if (!$until && $current !== $employer && $date !== $from) {
            return null;
        }

        foreach ($assignments as $event) {
            $logged = substr((string) $event->created_at, 0, 10);
            if ($logged < $from || ($until && $logged >= $until)) {
                continue;
            }

            $before = json_decode($event->before_links ?? 'null', true);
            $after = json_decode($event->after_links ?? 'null', true);
            $oldId = isset($before['employer_id']) ? (int) $before['employer_id'] : null;
            $newId = isset($after['employer_id']) ? (int) $after['employer_id'] : null;
            if ($oldId === $newId || $newId === $employer
                || ($until && $oldId === $employer && $newId !== null && $newId === $later[$until])) {
                continue;
            }

            // A profile change has a recording time, but cannot date the actual change in employment.
            return null;
        }

        return $employer;
    }
}
