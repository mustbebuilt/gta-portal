<?php

namespace App\Http\Controllers;

use App\Support\EmployerAttendance;
use App\Support\LearningAccess;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class EmployerAttendanceReportController extends Controller
{
    public function __invoke(Request $request)
    {
        abort_unless(LearningAccess::manager($request->user()), 403);
        $filters = $request->validate([
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:today'],
            'employer' => ['nullable', 'regex:/^(unknown|[1-9][0-9]*)$/'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
        $through = $filters['to'] ?? now('Europe/London')->startOfWeek()->subDay()->toDateString();
        $from = $filters['from'] ?? now('Europe/London')->startOfWeek()->subWeeks(8)->toDateString();
        [$rows, $employers] = EmployerAttendance::keptAtWork($from, $through);
        $groups = EmployerAttendance::groups($rows, $employers);
        $selected = $filters['employer'] ?? null;
        abort_if($selected !== null && $selected !== 'unknown' && !$employers->has($selected), 404);
        $filtered = $rows->when($selected, fn ($all) => $all->filter(fn ($row) => (string) $row['employer'] === $selected))
            ->sortBy([['date', 'desc'], ['session', 'desc'], ['apprentice.id', 'asc']])->values();
        $page = (int) ($filters['page'] ?? 1);
        $details = new LengthAwarePaginator($filtered->forPage($page, 25)->values(), $filtered->count(), 25, $page,
            ['path' => $request->url(), 'query' => $request->except('page')]);

        return response()->view('retention.employer-attendance', compact('from', 'through', 'selected', 'groups', 'details', 'employers'))
            ->header('Cache-Control', 'no-store, private');
    }
}
