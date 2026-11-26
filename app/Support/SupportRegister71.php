<?php

namespace App\Support;

use App\Models\{Apprentice, SupportConcern, SupportMarkerSchedule71, SupportPlan, SupportSharedAction71, User};
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Pack 71: Merged Support & Retention Register, IM1-IM40 marker schedules & shared actions. */
final class SupportRegister71
{
    public const INTERVALS = [
        'weekly' => 'Weekly',
        'fortnightly' => 'Fortnightly',
        'monthly' => 'Monthly',
        'three_monthly' => 'Three-monthly',
        'six_monthly' => 'Six-monthly (maximum)',
    ];

    public static function codes(): array
    {
        return json_decode(file_get_contents(resource_path('data/inclusion-markers.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function addInterval(string $baseDate, string $interval): string
    {
        $dt = new DateTimeImmutable($baseDate.' 00:00:00', new \DateTimeZone('UTC'));

        return match ($interval) {
            'weekly' => $dt->modify('+7 days')->format('Y-m-d'),
            'fortnightly' => $dt->modify('+14 days')->format('Y-m-d'),
            'monthly' => self::addMonthsClamped($dt, 1),
            'three_monthly' => self::addMonthsClamped($dt, 3),
            'six_monthly' => self::addMonthsClamped($dt, 6),
            default => throw ValidationException::withMessages(['interval_key' => 'Choose a valid review interval up to six-monthly.']),
        };
    }

    private static function addMonthsClamped(DateTimeImmutable $dt, int $months): string
    {
        $first = $dt->modify('first day of this month')->modify('+'.$months.' months');
        $day = min((int) $dt->format('j'), (int) $first->format('t'));

        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), $day)->format('Y-m-d');
    }

    public static function allowed(User $user): bool
    {
        return $user->active && (SupportAccess::team($user) || in_array($user->role, ['admin', 'ceo', 'tutor', 'manager'], true));
    }

    public static function addMarker(User $actor, Apprentice $apprentice, array $data): SupportMarkerSchedule71
    {
        abort_unless(SupportAccess::team($actor, $apprentice), 403);
        $codes = self::codes();
        $code = (string) ($data['code'] ?? '');
        if (! isset($codes[$code])) {
            throw ValidationException::withMessages(['code' => 'Choose an approved IM1–IM40 marker code.']);
        }
        $note = trim((string) ($data['note'] ?? ''));
        if ($code === 'IM40' && ($note === '' || mb_strlen($note) > 250)) {
            throw ValidationException::withMessages(['note' => 'IM40 (Other) requires a brief description of 250 characters or fewer.']);
        }
        $interval = (string) ($data['interval_key'] ?? 'monthly');
        if (! isset(self::INTERVALS[$interval])) {
            throw ValidationException::withMessages(['interval_key' => 'Choose a valid review interval up to six-monthly.']);
        }
        $owner = User::whereKey((int) ($data['owner_id'] ?? 0))->where('active', true)->first();
        if (! $owner || in_array($owner->role, ['apprentice', 'employer'], true)) {
            throw ValidationException::withMessages(['owner_id' => 'Choose an active staff account as the marker owner.']);
        }

        return DB::transaction(function () use ($actor, $apprentice, $code, $note, $interval, $owner) {
            $locked = Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();
            abort_unless(SupportAccess::team($actor, $locked), 403);
            if (SupportConcern::where('apprentice_id', $locked->id)->where('code', $code)->where('status', '!=', 'Closed')->exists()) {
                throw ValidationException::withMessages(['code' => 'This marker already has an open review record.']);
            }
            $today = today()->toDateString();
            $firstDue = self::addInterval($today, $interval);

            $concern = new SupportConcern();
            $concern->apprentice_id = $locked->id;
            $concern->raised_by = $actor->id;
            $concern->initial_note = $note !== '' ? $note : ($code.' recorded on Support and Retention Register.');
            $concern->code = $code;
            $concern->other_code = $code === 'IM40' ? $note : null;
            $concern->owner_id = $owner->id;
            $concern->status = 'In progress';
            $concern->next_review = $firstDue;
            $concern->acknowledged_at = now();
            $concern->history = [[
                'event' => 'register_marker_added',
                'by' => $actor->id,
                'at' => now()->toIso8601String(),
                'code' => $code,
                'interval_key' => $interval,
            ]];
            $concern->save();

            $schedule = new SupportMarkerSchedule71();
            $schedule->apprentice_id = $locked->id;
            $schedule->support_concern_id = $concern->id;
            $schedule->interval_key = $interval;
            $schedule->first_review_completed = false;
            $schedule->last_formal_review_date = null;
            $schedule->next_due_date = $firstDue;
            $schedule->version = 1;
            $schedule->history = [[
                'event' => 'schedule_created',
                'by' => $actor->id,
                'at' => now()->toIso8601String(),
                'interval_key' => $interval,
                'next_due_date' => $firstDue,
            ]];
            $schedule->save();

            return $schedule;
        });
    }

    public static function recordReview(User $actor, SupportMarkerSchedule71 $schedule, array $data): SupportMarkerSchedule71
    {
        $apprentice = Apprentice::findOrFail($schedule->apprentice_id);
        abort_unless(SupportAccess::team($actor, $apprentice), 403);

        $kind = (string) ($data['kind'] ?? 'formal_review');
        if (! in_array($kind, ['formal_review', 'discussion_only', 'change_frequency', 'close_marker'], true)) {
            throw ValidationException::withMessages(['kind' => 'Choose a valid review action.']);
        }

        return DB::transaction(function () use ($actor, $schedule, $data, $kind) {
            $s = SupportMarkerSchedule71::whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            if ($s->version !== (int) ($data['version'] ?? 0)) {
                throw ValidationException::withMessages(['version' => 'This schedule changed while you were editing. Reload before saving.']);
            }
            $concern = $s->support_concern_id ? SupportConcern::whereKey($s->support_concern_id)->lockForUpdate()->first() : null;
            $note = trim((string) ($data['note'] ?? ''));
            $history = $s->history ?? [];

            abort_unless(SupportAccess::team($actor, $s->apprentice), 403);
            if (!$concern || $concern->status === 'Closed') {
                throw ValidationException::withMessages(['kind' => 'Choose an open marker.']);
            }
            if ($kind === 'formal_review') {
                $reviewDate = (string) ($data['review_date'] ?? today()->toDateString());
                $earliest = $s->last_formal_review_date?->toDateString() ?? $s->created_at->toDateString();
                if ($reviewDate < $earliest || $reviewDate > today()->toDateString()) {
                    throw ValidationException::withMessages(['review_date' => 'Use a date within this marker’s review history.']);
                }
                $interval = (string) ($data['interval_key'] ?? $s->interval_key);
                if (! isset(self::INTERVALS[$interval])) {
                    throw ValidationException::withMessages(['interval_key' => 'Choose a valid review interval up to six-monthly.']);
                }
                self::saveReviewTarget($actor, $concern, $data, $reviewDate);
                $nextDue = self::addInterval($reviewDate, $interval);
                $s->interval_key = $interval;
                $s->first_review_completed = true;
                $s->last_formal_review_date = $reviewDate;
                $s->next_due_date = $nextDue;
                $history[] = [
                    'event' => 'formal_review',
                    'context' => 'Register',
                    'by' => $actor->id,
                    'at' => now()->toIso8601String(),
                    'review_date' => $reviewDate,
                    'next_due_date' => $nextDue,
                    'review_outcome_code' => $data['review_outcome_code'] ?? null,
                    'note' => $note !== '' ? $note : (ReportedChoices::SUPPORT_REVIEW[$data['review_outcome_code'] ?? ''] ?? null),
                ];
                if ($concern && $concern->status !== 'Closed') {
                    $concern->next_review = $nextDue;
                    $concern->version++;
                    $concern->save();
                }
            } elseif ($kind === 'discussion_only') {
                // Discussion alone never moves last_formal_review_date or next_due_date.
                $history[] = [
                    'event' => 'discussion_only',
                    'context' => 'Register',
                    'by' => $actor->id,
                    'at' => now()->toIso8601String(),
                    'note' => $note !== '' ? $note : 'Discussed without formal review.',
                ];
            } elseif ($kind === 'change_frequency') {
                $newInterval = (string) ($data['interval_key'] ?? '');
                if (! isset(self::INTERVALS[$newInterval])) {
                    throw ValidationException::withMessages(['interval_key' => 'Choose a valid review interval up to six-monthly.']);
                }
                if ($note === '') {
                    throw ValidationException::withMessages(['note' => 'Record a reason when changing review frequency.']);
                }
                $oldInterval = $s->interval_key;
                $anchor = $s->last_formal_review_date?->toDateString() ?? $s->created_at->toDateString();
                $nextDue = self::addInterval($anchor, $newInterval);
                $s->interval_key = $newInterval;
                $s->next_due_date = $nextDue;
                $history[] = [
                    'event' => 'frequency_changed',
                    'by' => $actor->id,
                    'at' => now()->toIso8601String(),
                    'old_interval' => $oldInterval,
                    'new_interval' => $newInterval,
                    'next_due_date' => $nextDue,
                    'reason' => $note,
                ];
                if ($concern && $concern->status !== 'Closed') {
                    $concern->next_review = $nextDue;
                    $concern->version++;
                    $concern->save();
                }
            } elseif ($kind === 'close_marker') {
                if ($note === '') {
                    throw ValidationException::withMessages(['note' => 'Record a closure outcome before closing this marker episode.']);
                }
                if ($concern && $concern->status !== 'Closed') {
                    $concern->status = 'Closed';
                    $concern->outcome = $note;
                    $concern->closed_at = now();
                    $concern->version++;
                    $concern->save();
                }
                $history[] = [
                    'event' => 'marker_closed',
                    'by' => $actor->id,
                    'at' => now()->toIso8601String(),
                    'outcome' => $note,
                ];
            }

            $s->history = $history;
            $s->version++;
            $s->save();

            return $s;
        });
    }

    public static function reviewTargetRules(): array
    {
        return [
            'summary' => ['nullable', 'required_with:measure,owner_id,due_date', 'string', 'max:2000'],
            'measure' => ['nullable', 'required_with:summary', 'string', 'max:1000'],
            'owner_id' => ['nullable', 'required_with:summary', 'integer'],
            'due_date' => ['nullable', 'required_with:summary', 'date_format:Y-m-d'],
            'request_uuid' => ['nullable', 'required_with:summary', 'uuid'],
            'after_review' => ['nullable', 'in:next'],
            'next_meeting' => ['nullable', 'required_if:after_review,next', 'integer', 'min:1'],
            'meeting_filters' => ['nullable', 'array:view,search,by,marker'],
            'meeting_filters.view' => ['nullable', 'in:all,needs-review'],
            'meeting_filters.search' => ['nullable', 'string', 'max:100'],
            'meeting_filters.by' => ['nullable', 'date_format:Y-m-d'],
            'meeting_filters.marker' => ['nullable', 'string', 'max:8'],
        ];
    }

    public static function saveReviewTarget(User $actor, SupportConcern $marker, array $data, string $date): void
    {
        if (!filled($data['summary'] ?? null)) {
            return;
        }
        if (($data['due_date'] ?? '') < $date) {
            throw ValidationException::withMessages(['due_date' => 'Choose a target date on or after the review date.']);
        }
        if (!SupportAccess::owners($marker->apprentice)->contains('id', (int) $data['owner_id'])) {
            throw ValidationException::withMessages(['owner_id' => 'Choose an eligible owner for this apprentice.']);
        }
        $target = self::createAction($actor, $marker->apprentice, $data + ['support_concern_id' => $marker->id]);
        if ($target->support_concern_id !== $marker->id || $target->created_by !== $actor->id
            || $target->owner_id !== (int) $data['owner_id'] || $target->due_date->toDateString() !== $data['due_date']
            || ($target->details['summary'] ?? null) !== trim($data['summary'])
            || ($target->details['measure'] ?? null) !== $data['measure']) {
            throw ValidationException::withMessages(['summary' => 'This target request was already used. Reload the review before trying again.']);
        }
    }

    public static function reviewReturn(\Illuminate\Http\Request $request, array $data): \Illuminate\Http\RedirectResponse
    {
        if (($data['after_review'] ?? null) === 'next' && isset($data['next_meeting'])
            && ($data['kind'] ?? 'formal_review') === 'formal_review') {
            return to_route('support.worklist', ['meeting' => $data['next_meeting']] +
                ($data['meeting_filters'] ?? []));
        }

        return back();
    }

    public static function createAction(User $actor, Apprentice $apprentice, array $data): SupportSharedAction71
    {
        abort_unless(SupportAccess::team($actor, $apprentice), 403);
        $owner = User::whereKey((int) ($data['owner_id'] ?? 0))->where('active', true)->first();
        if (! $owner || in_array($owner->role, ['apprentice', 'employer'], true)) {
            throw ValidationException::withMessages(['owner_id' => 'Choose an active staff account as the action owner.']);
        }
        $uuid = (string) ($data['request_uuid'] ?? Str::uuid());

        return DB::transaction(function () use ($actor, $apprentice, $owner, $data, $uuid) {
            $existing = SupportSharedAction71::where('request_uuid', $uuid)->first();
            if ($existing) {
                return $existing;
            }
            $concernId = ! empty($data['support_concern_id']) ? (int) $data['support_concern_id'] : null;
            if ($concernId) {
                SupportConcern::whereKey($concernId)->where('apprentice_id', $apprentice->id)->firstOrFail();
            }
            $planId = ! empty($data['support_plan_id']) ? (int) $data['support_plan_id'] : null;
            if ($planId) {
                SupportPlan::whereKey($planId)->where('apprentice_id', $apprentice->id)->firstOrFail();
            }

            $action = new SupportSharedAction71();
            $action->apprentice_id = $apprentice->id;
            $action->owner_id = $owner->id;
            $action->created_by = $actor->id;
            $action->support_concern_id = $concernId;
            $action->support_plan_id = $planId;
            $action->request_uuid = $uuid;
            $action->due_date = $data['due_date'];
            $action->state = 'Open';
            $action->version = 1;
            $action->details = [
                'summary' => trim((string) $data['summary']),
                ...(!empty($data['measure']) ? ['measure' => $data['measure']] : []),
                'outcome' => null,
            ];
            $action->history = [[
                'event' => 'action_created',
                'by' => $actor->id,
                'at' => now()->toIso8601String(),
                'owner_id' => $owner->id,
                'due_date' => $data['due_date'],
            ]];
            $action->save();

            return $action;
        });
    }

    public static function completeAction(User $actor, SupportSharedAction71 $action, array $data): SupportSharedAction71
    {
        $apprentice = Apprentice::findOrFail($action->apprentice_id);
        abort_unless(SupportAccess::team($actor, $apprentice), 403);

        return DB::transaction(function () use ($actor, $action, $data) {
            $a = SupportSharedAction71::whereKey($action->id)->lockForUpdate()->firstOrFail();
            if ($a->state === 'Completed') {
                return $a;
            }
            $details = $a->details ?? [];
            $details['outcome'] = trim((string) ($data['outcome'] ?? 'Completed'));
            $history = $a->history ?? [];
            $history[] = [
                'event' => 'action_completed',
                'by' => $actor->id,
                'at' => now()->toIso8601String(),
                'outcome' => $details['outcome'],
            ];
            $a->state = 'Completed';
            $a->completed_at = now();
            $a->details = $details;
            $a->history = $history;
            $a->version++;
            $a->save();

            return $a;
        });
    }

    public static function query(User $actor, array $filters = [], ?string $today = null): array
    {
        abort_unless(self::allowed($actor), 403);
        $today ??= today()->toDateString();
        $view = ($filters['view'] ?? 'needs_review') === 'all' ? 'all' : 'needs_review';
        $search = trim((string) ($filters['search'] ?? ''));
        $codeFilter = trim((string) ($filters['code'] ?? ''));

        $visibleIds = SupportAccess::team($actor)
            ? SupportAccess::ids($actor)
            : Apprentice::visibleTo($actor)->pluck('id')->all();

        $apprentices = Apprentice::whereIn('id', $visibleIds)
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('reference', 'like', '%'.$search.'%')
                ->orWhere('first_name', 'like', '%'.$search.'%')
                ->orWhere('last_name', 'like', '%'.$search.'%')))
            ->with(['employerRecord', 'groupRecord'])
            ->orderBy('last_name')->orderBy('first_name')
            ->get()
            ->keyBy('id');

        $ids = $apprentices->keys()->all();
        $concerns = SupportConcern::whereIn('apprentice_id', $ids)->where('status', '!=', 'Closed')->with('owner')->get()->groupBy('apprentice_id');
        $schedules = SupportMarkerSchedule71::whereIn('apprentice_id', $ids)->get()->keyBy('support_concern_id');
        $plans = SupportPlan::whereIn('apprentice_id', $ids)->where('status', '!=', 'Closed')->get()->groupBy('apprentice_id');
        $actions = SupportSharedAction71::whereIn('apprentice_id', $ids)->with('owner')->orderBy('due_date')->get()->groupBy('apprentice_id');
        $codes = self::codes();

        $rows = [];
        $distinctApprentices = [];
        $distinctNeedsReview = [];
        $byCode = [];
        $openActionsCount = 0;

        foreach ($apprentices as $id => $apprentice) {
            $canPrivate = SupportAccess::team($actor, $apprentice);
            $appConcerns = $concerns->get($id, collect());
            $appPlans = $plans->get($id, collect());
            $appActions = $actions->get($id, collect());

            if ($appConcerns->isEmpty() && $appPlans->isEmpty()) {
                continue;
            }

            $markerRows = [];
            $appNeedsReview = false;

            foreach ($appConcerns as $c) {
                if ($codeFilter !== '' && $c->code !== $codeFilter) {
                    continue;
                }
                $sched = $schedules->get($c->id);
                $due = $sched?->next_due_date?->toDateString() ?? $c->next_review?->toDateString();
                $firstDone = $sched ? $sched->first_review_completed : false;
                $needsCheck = ! $sched && ! $due;
                $isOverdue = $due !== null && $due < $today;
                $isDueToday = $due === $today;
                $needsReview = $needsCheck || ! $firstDone || $isOverdue || $isDueToday;

                if ($needsReview) {
                    $appNeedsReview = true;
                }
                $cKey = $c->code ?? 'UNCODED';
                $byCode[$cKey] = ($byCode[$cKey] ?? 0) + 1;

                $markerRows[] = [
                    'concern' => $c,
                    'schedule' => $sched,
                    'code' => $c->code,
                    'label' => $c->code ? ($codes[$c->code] ?? $c->code) : 'Uncoded routine support',
                    'interval_key' => $sched?->interval_key ?? 'needs_checking',
                    'first_review_completed' => $firstDone,
                    'last_formal_review_date' => $sched?->last_formal_review_date?->toDateString(),
                    'next_due_date' => $due,
                    'needs_review' => $needsReview,
                    'status_label' => $needsCheck
                        ? 'Schedule needs checking / first review not established'
                        : (! $firstDone ? 'First review due' : ($isOverdue ? 'Overdue' : ($isDueToday ? 'Due today' : 'Scheduled'))),
                ];
            }

            if ($codeFilter !== '' && ! $markerRows) {
                continue;
            }

            $distinctApprentices[$id] = true;
            if ($appNeedsReview) {
                $distinctNeedsReview[$id] = true;
            }
            $openActions = $appActions->where('state', 'Open')->values();
            $openActionsCount += $openActions->count();

            if ($view === 'needs_review' && ! $appNeedsReview) {
                continue;
            }

            $rows[] = [
                'apprentice' => $apprentice,
                'can_private' => $canPrivate,
                'markers' => $markerRows,
                'active_plan_count' => $appPlans->count(),
                'actions' => $canPrivate ? $appActions->values() : collect(),
                'open_action_count' => $openActions->count(),
                'needs_review' => $appNeedsReview,
            ];
        }

        arsort($byCode);

        return [
            'today' => $today,
            'view' => $view,
            'rows' => $rows,
            'codes' => $codes,
            'summary' => [
                'distinct_apprentices' => count($distinctApprentices),
                'distinct_needs_review' => count($distinctNeedsReview),
                'open_actions' => $openActionsCount,
                'by_code' => $byCode,
            ],
        ];
    }
}
