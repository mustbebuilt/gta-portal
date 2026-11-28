<?php

namespace App\Support;

use App\Models\{Apprentice, Employer, ProgrammeOutcome, TimetableGovernanceState, TimetablePlan, TimetablePlanEntry, TrainingSession, TutorGroup};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TeachingAllocationChecks
{
    /** Use the same transaction lock as timetable publication. */
    public static function lock(): void
    {
        TimetableGovernanceState::whereKey(1)->lockForUpdate()->firstOrFail();
    }

    public static function weeklyRows(array $dates, ?int $excludeGroup = null): array
    {
        if (!$dates) {
            return [];
        }

        $groups = TutorGroup::where('active', true)->whereNotNull('weekly_register')
            ->when($excludeGroup, fn ($query) => $query->whereKeyNot($excludeGroup))->get();
        $members = Apprentice::whereIn('tutor_group_id', $groups->modelKeys())
            ->get(['id', 'tutor_group_id'])->groupBy('tutor_group_id');
        $opened = TrainingSession::whereIn('tutor_group_id', $groups->modelKeys())
            ->whereIn(DB::raw('DATE(session_date)'), $dates)->whereNotNull('regular_week')->get()
            ->keyBy(fn ($session) => $session->tutor_group_id.':'.$session->session_date->toDateString());

        $rows = [];
        foreach ($groups as $group) {
            foreach ($dates as $date) {
                $next = WeeklyRegisters::next($group, CarbonImmutable::parse($date));
                if (($next['date'] ?? null) === $date && !$opened->has($group->id.':'.$date)) {
                    $rows[] = self::row($date, $group->id, $members->get($group->id)?->pluck('id')->all() ?? []);
                }
            }
        }

        return $rows;
    }

    public static function row(string $date, int $groupId, array $members): array
    {
        return ['work_date' => $date, 'group_id' => $groupId, 'kind' => 'Teaching', 'members' => $members];
    }

    public static function employerErrors(array $candidates, array $otherRows): array
    {
        $candidates = array_filter($candidates, fn ($row) => $row['kind'] === 'Teaching' && $row['members']);
        if (!$candidates) {
            return [];
        }
        $days = array_flip(array_column($candidates, 'work_date'));
        $others = array_filter($otherRows, fn ($row) => $row['kind'] === 'Teaching' && isset($days[$row['work_date']]));
        $ids = array_unique(array_merge(...array_column([...$candidates, ...$others], 'members')));
        $people = Apprentice::whereIn('id', $ids)->whereNotNull('employer_id')->get(['id', 'employer_id', 'start_date'])->keyBy('id');
        $closed = ProgrammeOutcome::whereIn('apprentice_id', $people->modelKeys())->get()
            ->filter(fn ($outcome) => !empty($outcome->context['epa_closure']['archived_at'])
                || in_array($outcome->context['status'] ?? null, ['Completed', 'Withdrawn', 'Transferred', 'Break in learning'], true))
            ->pluck('apprentice_id')->flip();
        $employers = Employer::whereIn('id', $people->pluck('employer_id'))->pluck('reference', 'id');
        $byEmployer = static function (array $row) use ($people, $closed): array {
            $byEmployer = [];
            foreach ($row['members'] as $id) {
                $person = $people->get($id);
                if ($person && !$closed->has($id) && (!$person->start_date || $person->start_date->toDateString() <= $row['work_date'])) {
                    $byEmployer[$person->employer_id][] = (int) $id;
                }
            }

            return $byEmployer;
        };

        $existing = [];
        foreach ($others as $row) {
            foreach ($byEmployer($row) as $employer => $learners) {
                $existing[$row['work_date']][$employer] = [...($existing[$row['work_date']][$employer] ?? []), ...$learners];
            }
        }

        $errors = [];
        foreach ($candidates as $row) {
            $date = $row['work_date'];
            foreach ($byEmployer($row) as $employer => $learners) {
                $otherLearners = $existing[$date][$employer] ?? [];
                if (count(array_unique([...$learners, ...$otherLearners])) > 1) {
                    $reference = $employers[$employer] ?? $employer;
                    $errors[] = "Employer release clash on {$date} for {$reference}. Apprentices at the same employer need different teaching days.";
                }
                $existing[$date][$employer] = [...$otherLearners, ...$learners];
            }
        }

        return array_values(array_unique($errors));
    }

    public static function checkDates(TutorGroup $group, array $members, array $dates, string $field, array $ignoreSessions = [], bool $lock = true): void
    {
        $dates = array_values(array_filter($dates, fn ($date) => $date >= today()->toDateString()));
        if (!$dates || !$members) {
            return;
        }
        $busy = CentrePlanning::busy($dates, null, $lock)['rows'];
        $busy = array_filter($busy, fn ($row) => $row['plan_id'] !== null
            && !in_array($row['linked_session'] ?? null, $ignoreSessions, true));
        // A saved register still counts for employer release if its tutor or room needs checking.
        $registers = TrainingSession::whereIn(DB::raw('DATE(session_date)'), $dates)->where('state', '!=', 'Cancelled')
            ->whereNotIn('id', $ignoreSessions)->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get();
        foreach ($registers as $session) {
            $roster = RegisterFollowup::roster($session);
            $busy[] = self::row($session->session_date->toDateString(), $session->tutor_group_id, $roster['ids']);
        }
        $errors = self::employerErrors(
            array_map(fn ($date) => self::row($date, $group->id, $members), $dates),
            [...$busy, ...self::weeklyRows($dates, $group->id)],
        );
        if ($errors) {
            throw ValidationException::withMessages([$field => $errors]);
        }
    }

    public static function weeks(string $date): array
    {
        $first = CarbonImmutable::parse($date);

        return array_map(fn ($week) => $first->addWeeks($week)->toDateString(), range(0, 52));
    }

    public static function checkAssignment(Apprentice $candidate): void
    {
        if (!$candidate->tutor_group_id) {
            return;
        }
        $group = TutorGroup::findOrFail($candidate->tutor_group_id);
        $field = 'tutor_group_id';
        $people = Apprentice::where('tutor_group_id', $group->id)->whereKeyNot($candidate->id)
            ->get(['id', 'programme_id', 'start_date']);
        if ($candidate->programme_id && (($group->programme_id && $group->programme_id != $candidate->programme_id)
            || $people->contains(fn ($person) => $person->programme_id && $person->programme_id != $candidate->programme_id))) {
            throw ValidationException::withMessages([$field => 'Choose a class on the apprentice’s apprenticeship programme and version.']);
        }

        $from = max(today()->toDateString(), $candidate->start_date?->toDateString() ?? '');
        $dates = TrainingSession::where('tutor_group_id', $group->id)->where('state', '!=', 'Cancelled')
            ->whereDate('session_date', '>=', $from)->get(['session_date'])
            ->map(fn ($session) => $session->session_date->toDateString())->all();
        $planDates = TimetablePlanEntry::where('group_id', $group->id)->where('kind', 'Teaching')
            ->whereDate('work_date', '>=', $from)
            ->whereIn('plan_id', TimetablePlan::where('state', 'Published')->select('id'))
            ->get(['work_date'])->map(fn ($entry) => $entry->work_date->toDateString())->all();

        $weeklyDates = [];
        $next = WeeklyRegisters::next($group, CarbonImmutable::parse($from));
        $through = CarbonImmutable::parse($next['date'] ?? $from)->addWeeks(52)->toDateString();
        while ($next && $next['date'] <= $through) {
            $weeklyDates[] = $next['date'];
            $next = WeeklyRegisters::next($group, CarbonImmutable::parse($next['date'])->addDay());
        }
        $dates = array_values(array_unique([...$dates, ...$planDates, ...$weeklyDates]));
        $date = $dates ? min($dates) : $from;
        $year = self::trainingYear($candidate, $date);
        preg_match('/\byear[\s-]*([1-3])\b/i', $group->name, $label);
        $groupYear = isset($label[1]) ? (int) $label[1] : null;
        if ($year === null && $groupYear !== null) {
            throw ValidationException::withMessages([$field => 'Record the apprentice’s training start date before adding them to this year group.']);
        }
        if ($year !== null && (($groupYear !== null && $year !== $groupYear) || $people->contains(
            fn ($person) => ($peerYear = self::trainingYear($person, $date)) !== null && $peerYear !== $year
        ))) {
            throw ValidationException::withMessages([$field => "Training position mismatch. This apprentice is in Year {$year} on {$date}. Choose a class at the same training stage."]);
        }

        self::checkDates($group, [...$people->modelKeys(), $candidate->id], $dates, $field);
    }

    private static function trainingYear(Apprentice $person, string $date): ?int
    {
        if (!$person->start_date || $person->start_date->toDateString() > $date) {
            return null;
        }

        return (int) $person->start_date->diffInYears(CarbonImmutable::parse($date)) + 1;
    }
}
