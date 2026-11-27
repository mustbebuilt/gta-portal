<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\ProgrammeOutcome;

final class ReportOutcomes
{
    public static function defaultPeriod(): array
    {
        $today = today();
        $start = $today->copy()->month(8)->day(1);
        if ($start->gt($today)) {
            $start->subYear();
        }

        return ['from' => $start->toDateString(), 'to' => $today->toDateString(), 'programme' => null];
    }

    public static function capture(array $filters): array
    {
        $apprentices = Apprentice::with('programmeRecord')->when($filters['programme'], fn ($query, $id) => $query->where('programme_id', $id));
        $outcomes = ProgrammeOutcome::whereIn('apprentice_id', (clone $apprentices)->select('id'))
            ->where(function ($query) {
                $query->where('context->status', 'Withdrawn')->orWhere(fn ($q) =>
                    $q->where('context->status', 'Completed')->where('context->achievement', 'Achieved'));
            })->get(['apprentice_id', 'context']);
        $people = $apprentices->whereIn('id', $outcomes->pluck('apprentice_id'))->get()->keyBy('id');

        $achievers = collect();
        $earlyLeavers = collect();
        $checks = collect();
        foreach ($outcomes as $outcome) {
            $a = $people->get($outcome->apprentice_id);
            if (!$a) {
                continue;
            }
            $context = $outcome->context;
            $withdrawal = $context['status'] === 'Withdrawn';
            $event = $context[$withdrawal ? 'endDate' : 'achievementDate'] ?? null;
            if (CohortInsights::validDate($event) && ($event < $filters['from'] || $event > $filters['to'])) {
                continue;
            }
            $row = [
                'apprentice' => $a,
                'date' => CohortInsights::validDate($event) ? $event : null,
                'planned' => RetentionAnalysis::plannedEnd($a),
                'exit_employment' => RetentionAnalysis::employmentAtExit($context),
                'grade' => $context['epa_closure']['grade'] ?? null,
                'attempt' => $context['epa_closure']['attempt'] ?? null,
                'reason' => $withdrawal ? ($context['reason'] ?? 'Not established') : null,
                'reason_basis' => $withdrawal ? ($context['reasonBasis'] ?? 'Not confirmed') : null,
            ];
            if ($withdrawal) {
                $timing = RetentionAnalysis::withdrawalTiming($a, $context);
                if ($timing === 'Early leaver') {
                    $earlyLeavers->push($row);
                } elseif ($timing !== 'Withdrawn on or after planned end') {
                    $checks->push($row + ['issue' => $timing]);
                }
            } elseif (CohortInsights::outcome($a, $context)['needs_check']) {
                $checks->push($row + ['issue' => 'Check achievement source or date']);
            } else {
                $achievers->push($row);
            }
        }

        return [
            'achievers' => $achievers->sortByDesc('date'),
            'earlyLeavers' => $earlyLeavers->sortByDesc('date'),
            'checks' => $checks->sortByDesc('date'),
        ];
    }
}
