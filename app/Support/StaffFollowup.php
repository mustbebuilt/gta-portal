<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class StaffFollowup
{
    public const STAFF_VIEWS = ['all', 'active', 'inactive', 'unrecorded'];
    public const ACTION_VIEWS = ['all', 'overdue', 'today', 'checking', 'upcoming', 'dates', 'checks'];
    public const REVIEW_VIEWS = ['all', 'overdue', 'today', 'upcoming', 'dates', 'checks'];

    public static function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) { return null; }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    public static function for(Collection $people, Collection $profiles, int $actorId, bool $ceo): array
    {
        $summary = []; $actions = collect(); $reviews = collect();
        foreach ($people as $person) {
            $profile = $profiles->get($person->id); $payload = $profile?->payload;
            $valid = $payload === null || is_array($payload); $v = is_array($payload) ? $payload : [];
            $manager = $person->id !== $actorId && ($ceo || $profile?->manager_id === $actorId);
            $entries = collect(is_array($v['entries'] ?? null) ? $v['entries'] : [])->filter(fn ($e) => is_array($e) && ($manager || ($e['kind'] ?? null) === 'cpd' || ($e['shared'] ?? false)));
            $s = ['recorded' => (bool) $profile, 'entries' => $entries->count(), 'open_actions' => 0, 'overdue_actions' => 0, 'due_reviews' => 0, 'date_checks' => 0, 'source_checks' => 0];
            foreach (['actions', 'schedules'] as $key) {
                $list = $v[$key] ?? [];
                if (! $valid || ! is_array($list) || ! array_is_list($list)) { $list = [null]; }
                foreach ($list as $item) {
                    $isAction = $key === 'actions'; $item = is_array($item) ? $item : [];
                    $status = is_string($item['status'] ?? null) ? $item['status'] : 'Source check';
                    if (($isAction && $status === 'Completed') || (! $isAction && in_array($status, ['Completed', 'Cancelled'], true))) { continue; }
                    $known = $isAction ? in_array($status, ['In progress', 'Ready for manager check'], true) : $status === 'Planned';
                    $kind = is_string($item['kind'] ?? null) ? $item['kind'] : null;
                    if (! $isAction && ! in_array($kind, ['observation', 'one-to-one', 'appraisal'], true)) { $known = false; }
                    $due = self::date($item['due'] ?? null);
                    $flags = ['all' => true, 'overdue' => $known && $due !== null && $due < today()->toDateString(), 'today' => $known && $due === today()->toDateString(), 'upcoming' => $known && $due !== null && $due > today()->toDateString(), 'dates' => $due === null, 'checks' => ! $known];
                    if ($isAction) { $flags['checking'] = $status === 'Ready for manager check'; }
                    // Deliberately omit titles, targets, notes, evidence, history and employee responses.
                    $row = ['person' => $person, 'manager' => $manager, 'id' => is_string($item['id'] ?? null) ? $item['id'] : null, 'status' => $status, 'kind' => $kind, 'due' => $due, 'flags' => $flags];
                    if ($isAction) {
                        $actions->push($row); $s['open_actions']++; $s['overdue_actions'] += (int) $flags['overdue']; $s['date_checks'] += (int) $flags['dates'];
                    } else {
                        $reviews->push($row); $s['due_reviews'] += (int) ($flags['overdue'] || $flags['today']);
                    }
                    $s['source_checks'] += (int) ! $known;
                }
            }
            $summary[$person->id] = $s;
        }
        $sort = function (Collection $rows): Collection {
            return $rows->sort(fn ($a, $b) => [$a['flags']['checks'] ? 0 : ($a['flags']['dates'] ? 1 : ($a['flags']['overdue'] ? 2 : 3)), $a['due'] ?? '', $a['person']->id, $a['id'] ?? ''] <=> [$b['flags']['checks'] ? 0 : ($b['flags']['dates'] ? 1 : ($b['flags']['overdue'] ? 2 : 3)), $b['due'] ?? '', $b['person']->id, $b['id'] ?? ''])->values();
        };
        return ['summary' => $summary, 'actions' => $sort($actions), 'reviews' => $sort($reviews), 'actionCounts' => self::counts($actions, self::ACTION_VIEWS), 'reviewCounts' => self::counts($reviews, self::REVIEW_VIEWS)];
    }

    private static function counts(Collection $rows, array $views): array
    {
        $counts = [];
        foreach ($views as $view) { $counts[$view] = $rows->filter(fn ($row) => $row['flags'][$view])->count(); }
        return $counts;
    }

    public static function paginate(Collection $rows, Request $request, string $pageName): LengthAwarePaginator
    {
        $page = min((int) ($request->input($pageName) ?? 1), max(1, (int) ceil($rows->count() / 20)));
        return new LengthAwarePaginator($rows->slice(($page - 1) * 20, 20)->values(), $rows->count(), 20, $page, ['path' => $request->url(), 'query' => $request->except($pageName), 'pageName' => $pageName]);
    }
}
