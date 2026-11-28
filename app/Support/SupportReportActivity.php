<?php

namespace App\Support;

use Illuminate\Support\Collection;

final class SupportReportActivity
{
    public static function forPeriod(Collection $plans, string $start, string $end): array
    {
        $within = static fn (array $record): bool =>
            CohortInsights::validDate($record['date'] ?? null) && $record['date'] >= $start && $record['date'] <= $end;

        $activities = 0;
        $people = [];
        $reviews = collect();
        foreach ($plans as $plan) {
            $logged = array_filter($plan->activities ?? [], $within);
            $activities += count($logged);
            if ($logged) {
                $people[$plan->apprentice_id] = true;
            }
            foreach (array_filter($plan->reviews ?? [], $within) as $review) {
                $reviews->push($review['direction'] ?? '');
            }
        }

        $directions = [
            'continue' => 'Continue support',
            'adjust_support' => 'Adjust support',
            'escalate' => 'Escalate support',
            'step_down_close' => 'Step down or close',
        ];
        $counts = array_fill_keys(array_values($directions), 0) + ['Decision not recorded' => 0];
        foreach ($reviews as $direction) {
            $counts[$directions[$direction] ?? 'Decision not recorded']++;
        }

        return ['activities' => $activities, 'apprentices' => count($people),
            'reviews' => $reviews->count(), 'decisions' => $counts];
    }
}
