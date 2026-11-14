<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class SupportFollowup
{
    public const CONCERN_VIEWS = ['open', 'new', 'overdue', 'today', 'weekly', 'dates', 'owners', 'closed'];
    public const PLAN_VIEWS = ['all', 'due', 'overdue', 'today', 'upcoming', 'checks', 'draft', 'closed'];

    public static function concerns(Collection $concerns): array
    {
        $rows = collect(); $owners = [];
        foreach ($concerns as $c) {
            $open = $c->status !== 'Closed';
            $action = $c->action_due?->toDateString(); $review = $c->next_review?->toDateString();
            $key = $c->owner_id.':'.$c->apprentice_id;
            if (! array_key_exists($key, $owners)) { $owners[$key] = $c->owner && SupportAccess::team($c->owner, $c->apprentice); }
            $flags = ['open' => $open, 'new' => $open && ! $c->acknowledged_at, 'overdue' => $open && $action !== null && $action < today()->toDateString(), 'today' => $open && $action === today()->toDateString(), 'weekly' => $open && ($review === null || $review <= today()->toDateString()), 'dates' => $open && ($action === null || $review === null), 'owners' => $open && ! $owners[$key], 'closed' => ! $open];
            $rows->push(['concern' => $c, 'action_due' => $action, 'next_review' => $review, 'flags' => $flags]);
        }
        return ['rows' => $rows, 'counts' => self::counts($rows, self::CONCERN_VIEWS)];
    }

    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) { return null; }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    public static function plans(Collection $plans, Collection $people): array
    {
        $people = $people->keyBy('id'); $rows = collect();
        foreach ($plans as $p) {
            if (! $people->has($p->apprentice_id)) { continue; }
            $due = null; $bucket = 'checks';
            if ($p->status === 'Closed') { $bucket = 'closed'; }
            elseif ($p->status === 'Draft' && ! $p->active_plan) { $bucket = 'draft'; }
            elseif ($p->status === 'Active' && is_array($p->active_plan) && is_string($p->active_plan['approved_at'] ?? null) && self::date(substr($p->active_plan['approved_at'], 0, 10))) {
                try { $due = self::date($p->nextReview()); } catch (\TypeError|\ErrorException $e) { $due = null; }
                if ($due) { $bucket = $due < today()->toDateString() ? 'overdue' : ($due === today()->toDateString() ? 'today' : 'upcoming'); }
            }
            // Only this metadata leaves the classifier; plan text and review evidence stay at source.
            $rows->push(['id' => $p->id, 'apprentice' => $people[$p->apprentice_id], 'status' => $p->status, 'due' => $due, 'bucket' => $bucket, 'flags' => [...array_fill_keys(self::PLAN_VIEWS, false), $bucket => true, 'all' => true, 'due' => in_array($bucket, ['overdue', 'today'], true)]]);
        }
        $rank = ['overdue' => 0, 'today' => 1, 'checks' => 2, 'upcoming' => 3, 'draft' => 4, 'closed' => 5];
        $rows = $rows->sort(fn ($a, $b) => [$rank[$a['bucket']], $a['due'] ?? '', $a['id']] <=> [$rank[$b['bucket']], $b['due'] ?? '', $b['id']])->values();
        return ['rows' => $rows, 'counts' => self::counts($rows, self::PLAN_VIEWS)];
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
