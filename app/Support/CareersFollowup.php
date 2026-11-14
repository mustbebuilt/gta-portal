<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class CareersFollowup
{
    public const VIEWS = ['all', 'overdue', 'today', 'checking', 'dates', 'assignments'];

    public static function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) { return null; }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    public static function actionsReadable(mixed $actions): bool
    {
        if ($actions === null) { return true; }
        if (! is_array($actions) || ! array_is_list($actions)) { return false; }
        $ids = [];
        foreach ($actions as $a) {
            if (! is_array($a)) { return false; }
            foreach (['id', 'title', 'owner', 'status', 'criteria', 'support', 'agreed_at'] as $key) {
                if (! isset($a[$key]) || ! is_string($a[$key])) { return false; }
            }
            if ($a['id'] === '' || in_array($a['id'], $ids, true) || ! in_array($a['owner'], ['Apprentice', 'Careers adviser'], true) || ! in_array($a['status'], ['To do', 'Ready for adviser check', 'Completed'], true)) { return false; }
            if (isset($a['due_date']) && ! is_string($a['due_date'])) { return false; }
            $ids[] = $a['id'];
        }
        return true;
    }

    public static function for(Collection $people, Collection $discussions): array
    {
        $people = $people->keyBy('id'); $rows = collect(); $sourceChecks = collect();
        foreach ($discussions as $d) {
            $a = $people->get($d->apprentice_id);
            if (! $a) { continue; }
            if (! self::actionsReadable($d->actions)) {
                $sourceChecks->push(['apprentice' => $a, 'discussion_id' => $d->id]);
                continue;
            }
            $adviser = $a->careersAdviser;
            $ownerOk = $adviser && StaffDuties::has($adviser,'careers_adviser');
            foreach ($d->actions ?? [] as $action) {
                if ($action['status'] === 'Completed') { continue; }
                $due = self::date($action['due_date'] ?? null);
                $checking = $action['status'] === 'Ready for adviser check';
                $flags = ['overdue' => $due !== null && $due < today()->toDateString(), 'today' => $due === today()->toDateString(), 'checking' => $checking, 'dates' => $due === null, 'assignments' => ! $ownerOk];
                $rows->push(['apprentice' => $a, 'discussion' => $d, 'action' => collect($action)->only(['id', 'title', 'owner', 'status'])->all(), 'due' => $due, 'overdue' => $flags['overdue'], 'flags' => $flags, 'owner' => $checking || $action['owner'] === 'Careers adviser' ? ($ownerOk ? $adviser->name : 'Adviser assignment needs checking') : $a->first_name.' '.$a->last_name, 'next' => $checking ? 'Check submitted work' : ($action['owner'] === 'Careers adviser' ? 'Complete agreed adviser action' : 'Check progress with apprentice')]);
            }
        }
        $rows = $rows->sort(fn ($a, $b) => [$a['flags']['dates'] ? 0 : ($a['overdue'] ? 1 : 2), $a['due'] ?? '', $a['apprentice']->id, $a['discussion']->id, $a['action']['id']] <=> [$b['flags']['dates'] ? 0 : ($b['overdue'] ? 1 : 2), $b['due'] ?? '', $b['apprentice']->id, $b['discussion']->id, $b['action']['id']])->values();
        $counts = ['all' => $rows->count()];
        foreach (array_diff(self::VIEWS, ['all']) as $view) { $counts[$view] = $rows->filter(fn ($row) => $row['flags'][$view])->count(); }
        return ['rows' => $rows, 'counts' => $counts, 'sourceChecks' => $sourceChecks];
    }

    public static function paginate(Collection $rows, Request $request, string $pageName, int $size = 20): LengthAwarePaginator
    {
        $page = min((int) ($request->input($pageName) ?? 1), max(1, (int) ceil($rows->count() / $size)));
        return new LengthAwarePaginator($rows->slice(($page - 1) * $size, $size)->values(), $rows->count(), $size, $page, ['path' => $request->url(), 'query' => $request->except($pageName), 'pageName' => $pageName]);
    }
}
