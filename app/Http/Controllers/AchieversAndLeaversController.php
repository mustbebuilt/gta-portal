<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\Programme;
use App\Models\ProgrammeOutcome;
use App\Support\AccountLifecycle;
use App\Support\CohortInsights;
use App\Support\LearningAccess;
use App\Support\RetentionAnalysis;
use Illuminate\Http\Request;

final class AchieversAndLeaversController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(LearningAccess::manager($request->user()) && !AccountLifecycle::blocked($request->user()), 403);
        $today = today();
        $start = $today->copy()->month(8)->day(1);
        if ($start->gt($today)) {
            $start->subYear();
        }
        $filters = validator([
            'from' => $request->query('from', $start->toDateString()),
            'to' => $request->query('to', $today->toDateString()),
            'programme' => $request->query('programme'),
        ], [
            'from' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:today'],
            'programme' => ['nullable', 'integer', 'exists:programmes,id'],
        ])->validate();

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

        return response()->view('reports.achievers-and-leavers', [
            'filters' => $filters, 'programmes' => Programme::orderBy('name')->get(['id','name']),
            'achievers' => $achievers->sortByDesc('date'), 'earlyLeavers' => $earlyLeavers->sortByDesc('date'),
            'checks' => $checks->sortByDesc('date'),
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
