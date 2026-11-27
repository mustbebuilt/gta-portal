<?php

namespace App\Http\Controllers;

use App\Models\Programme;
use App\Support\AccountLifecycle;
use App\Support\ReportOutcomes;
use App\Support\LearningAccess;
use Illuminate\Http\Request;

final class AchieversAndLeaversController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(LearningAccess::manager($request->user()) && !AccountLifecycle::blocked($request->user()), 403);
        $period = ReportOutcomes::defaultPeriod();
        $filters = validator([
            'from' => $request->query('from', $period['from']),
            'to' => $request->query('to', $period['to']),
            'programme' => $request->query('programme'),
        ], [
            'from' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:today'],
            'programme' => ['nullable', 'integer', 'exists:programmes,id'],
        ])->validate();

        return response()->view('reports.achievers-and-leavers', [
            'filters' => $filters, 'programmes' => Programme::orderBy('name')->get(['id','name']),
            ...ReportOutcomes::capture($filters),
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
