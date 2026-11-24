<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\ApprenticeEntryContext;
use App\Models\Employer;
use App\Models\EntryContextEvent;
use App\Models\ProgrammeOutcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

final class EmployerAttendance
{
    public static function keptAtWork(string $from, string $through, ?array $apprenticeIds = null): array
    {
        $people = Apprentice::where(fn ($q) => $q->whereNull('start_date')->orWhereDate('start_date', '<=', $through))
            ->when($apprenticeIds !== null, fn ($q) => $q->whereIn('id', $apprenticeIds))
            ->get(['id', 'reference', 'first_name', 'last_name', 'start_date', 'employer_id'])->keyBy('id');
        $ids = $people->modelKeys();
        $attendance = AttendanceLedger::forIds($ids, false, $from, $through, 'W');
        $people = $people->filter(fn ($apprentice) => $attendance[$apprentice->id]['rows'] !== []);
        $ids = $people->modelKeys();

        $entries = ApprenticeEntryContext::whereIn('apprentice_id', $ids)->get()->keyBy('apprentice_id');
        $employment = EntryContextEvent::whereIn('apprentice_id', $ids)->where('kind', 'Employment')
            ->get()->groupBy('apprentice_id');
        $assignments = DB::table('assignment_change_events')->whereIn('apprentice_id', $ids)
            ->get()->groupBy('apprentice_id');
        $outcomes = ProgrammeOutcome::whereIn('apprentice_id', $ids)->get()->keyBy('apprentice_id');
        $employers = Employer::get(['id', 'name', 'reference'])->keyBy('id');
        $rows = collect();

        foreach ($people as $person) {
            $timeline = EmploymentAtSession::timeline($entries->get($person->id), $employment->get($person->id, collect()));
            $start = $person->start_date?->toDateString();
            $context = $outcomes->get($person->id)?->context ?? [];
            $end = CohortInsights::outcome($person, $context)['end_date'];
            $closedWithUnknownDate = $end === null && in_array($context['status'] ?? null, ['Completed', 'Withdrawn', 'Transferred'], true);

            foreach ($attendance[$person->id]['rows'] as $session) {
                if ($session['cancelled'] || ($start && $session['date'] < $start) || ($end && $session['date'] > $end)) {
                    continue;
                }
                $employerId = $start && !$closedWithUnknownDate
                    ? EmploymentAtSession::on($session['date'], $timeline, $assignments->get($person->id, collect()), $person->employer_id)
                    : null;
                $rows->push([
                    'apprentice' => $person,
                    'session' => $session['id'],
                    'date' => $session['date'],
                    'employer' => $employers->has($employerId) ? $employerId : 'unknown',
                ]);
            }
        }

        return [$rows, $employers];
    }

    public static function groups(Collection $rows, Collection $employers): Collection
    {
        return $rows->groupBy('employer')->map(fn ($group, $key) => [
            'id' => (string) $key,
            'employer' => $employers->get($key),
            'apprentices' => $group->pluck('apprentice.id')->unique()->count(),
            'sessions' => $group->count(),
            'latest' => $group->max('date'),
            'records' => $group->sortByDesc('date')->values(),
        ])->sortBy([['sessions', 'desc'], ['latest', 'desc'], ['id', 'asc']]);
    }
}
