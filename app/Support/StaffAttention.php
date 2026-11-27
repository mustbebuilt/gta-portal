<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\EmployerPortalAction;
use App\Models\LearningTask;
use App\Models\ProgrammeOutcome;
use App\Models\ProgressReviewDraft;
use App\Models\SmartAction;
use App\Models\User;

class StaffAttention
{
    public const VIEWS = ['all', 'overdue', 'today', 'followup', 'checks'];

    public const REASONS = ['all', 'attendance', 'task', 'marking', 'action', 'review', 'progress', 'help', 'achievement', 'programme', 'employer'];

    public const RANK = ['overdue' => 0, 'today' => 1, 'followup' => 2, 'checks' => 3];

    public static function allowed(User $u): bool
    {
        return ReviewReminders::allowed($u);
    }

    public static function people(User $u)
    {
        abort_unless(self::allowed($u), 403);
        $q = Apprentice::select('id', 'reference', 'first_name', 'last_name', 'tutor_id', 'mentor_id', 'start_date', 'programme_id');
        if (! LearningAccess::manager($u)) {
            $q->where(function ($q) use ($u): void {
                $q->whereRaw('1=0');
                if ($u->role === 'tutor') {
                    $q->orWhere('tutor_id', $u->id);
                }if (StaffDuties::has($u, 'mentor')) {
                    $q->orWhere('mentor_id', $u->id);
                }
            });
        }

        return $q->orderBy('id')->get();
    }

    public static function for(User $u): array
    {
        $people = self::people($u);
        $ids = $people->pluck('id');
        $training = $people->filter(fn ($a) => LearningAccess::manager($u) || LearningAccess::tutor($u, $a))->pluck('id');
        $tasks = LearningTask::whereIn('apprentice_id', $training)
            ->orderBy('id')
            ->get([
                'id', 'apprentice_id', 'reference', 'block', 'status', 'due_date', 'submitted_at',
                'template->kind as template_kind', 'template->catalogueId as template_catalogue_id',
            ])
            ->each(function (LearningTask $task): void {
                $template = [];
                if ($task->template_kind !== null) {
                    $template['kind'] = $task->template_kind;
                }
                if ($task->template_catalogue_id !== null) {
                    $template['catalogueId'] = $task->template_catalogue_id;
                }
                $task->setAttribute('template', $template);
            })
            ->groupBy('apprentice_id');
        $actions = SmartAction::with('task')->whereIn('apprentice_id', $training)->whereNull('learning_task_id')->orderBy('id')->get()->groupBy('apprentice_id');
        $outcomes = ProgrammeOutcome::whereIn('apprentice_id', $ids)->get()->keyBy('apprentice_id');
        // Private requests and their counts never enter this combined view.
        $entries = ApprenticeExperience::queue($u)->where('kind', '!=', 'help_private')->where('state', 'New')->orderBy('id')->get(['id', 'apprentice_id', 'kind', 'created_at'])->groupBy('apprentice_id');
        $reviewDrafts = ProgressReviewDraft::whereIn('apprentice_id', $ids)->where('state', 'Draft')
            ->orderByDesc('updated_at')->orderByDesc('id')->get(['id', 'apprentice_id'])->groupBy('apprentice_id')->map->first();
        $rows = [];
        $employerReplies = EmployerPortalAction::whereIn('apprentice_id', $training)->where('state', 'Responded')->get(['id', 'apprentice_id', 'responded_at'])->groupBy('apprentice_id');

        $trainingSet = array_flip($training->all());
        $activeTrainingIds = [];
        $dueTasks = collect();
        $approvedCheckIds = [];
        foreach ($people as $a) {
            $context = $outcomes->get($a->id)?->context ?? [];
            $known = in_array($context['status'] ?? null, ['Active', 'Break in learning', 'Completed', 'Withdrawn', 'Transferred'], true) && filled($context['sourceRef'] ?? null);
            if ($known && $context['status'] === 'Active' && isset($trainingSet[$a->id])) {
                $activeTrainingIds[] = $a->id;
                $own = $tasks->get($a->id, collect());
                foreach ($own as $task) {
                    if ($task->status !== 'Awaiting marking' && ($task->editable() || $task->status === 'Source required')
                        && ($task->template['kind'] ?? 'source-pending') !== 'source-pending'
                        && $task->due_date && $task->due_date->lte(today())) {
                        $dueTasks->push($task);
                    }
                }
                $approvedCount = $own->where('status', 'Approved')->count();
                if ($approvedCount > 0 && $approvedCount < $own->count()) {
                    $approvedCheckIds[] = $a->id;
                }
            }
        }
        $blockReleases = BlockRelease::states($dueTasks);
        $approvedSummary = [];
        if ($approvedCheckIds) {
            LearningTask::whereIn('apprentice_id', $approvedCheckIds)
                ->where('status', 'Approved')
                ->orderBy('id')
                ->select(['id', 'apprentice_id', 'submissions'])
                ->chunkById(200, function ($chunk) use (&$approvedSummary): void {
                    foreach ($chunk as $t) {
                        $date = collect($t->submissions ?? [])->last()['decision']['date'] ?? null;
                        $approvedSummary[$t->apprentice_id] ??= ['dates' => [], 'unknown' => false];
                        if (! StaffTrainingRecords::validDate($date)) {
                            $approvedSummary[$t->apprentice_id]['unknown'] = true;
                        } else {
                            $approvedSummary[$t->apprentice_id]['dates'][] = $date;
                        }
                    }
                });
        }
        $attendanceByApprentice = StaffTrainingRecords::attendanceForMany(
            $activeTrainingIds,
            today()->subWeeks(6)->startOfWeek()->toDateString(),
            today()->toDateString()
        );

        foreach ($people as $a) {
            $reasons = [];
            $add = function (string $kind, string $bucket, string $text, ?string $date, string $url) use (&$reasons): void {
                $reasons[] = ['kind' => $kind, 'bucket' => $bucket, 'text' => $text, 'date' => $date, 'url' => $url];
            };
            $context = $outcomes->get($a->id)?->context ?? [];
            $known = in_array($context['status'] ?? null, ['Active', 'Break in learning', 'Completed', 'Withdrawn', 'Transferred'], true) && filled($context['sourceRef'] ?? null);
            $active = $known && $context['status'] === 'Active';
            $reviewUrl = ($draft = $reviewDrafts->get($a->id))
                ? route('reviews.edit', [$a, $draft]) : route('reviews.create', $a);
            if (! $known) {
                $add('programme', 'checks', 'Programme status/source needs checking', null, $reviewUrl);
            }
            if ($active) {
                $due = SignedReviews::cycle($a)['next_due'];
                if (! $due) {
                    $add('review', 'checks', 'Completed-review history needs checking; no reliable due reference', null, $reviewUrl);
                } elseif ($due <= today()->toDateString()) {
                    $add('review', $due < today()->toDateString() ? 'overdue' : 'today', 'Review due reference from latest checked signed copy', $due, $reviewUrl);
                }
            }
            if ($active && isset($trainingSet[$a->id])) {
                $own = $tasks->get($a->id, collect());
                foreach ($own as $task) {
                    $url = route('tasks.show', $task);
                    if ($task->status === 'Awaiting marking') {
                        if (! $task->submitted_at || $task->submitted_at->gt(now())) {
                            $add('marking', 'checks', 'Submission date needs checking: '.$task->reference, null, $url);
                        } elseif ($task->markingOverdue()) {
                            $add('marking', 'overdue', 'Tutor marking past seven-day reference: '.$task->reference, $task->submitted_at->copy()->addDays(7)->toDateString(), $url);
                        } elseif ($task->submitted_at->copy()->addDays(7)->isToday()) {
                            $add('marking', 'today', 'Tutor marking reaches seven-day reference today: '.$task->reference, today()->toDateString(), $url);
                        }

                        continue;
                    }
                    if (! $task->editable() && $task->status !== 'Source required') {
                        continue;
                    }
                    if (($task->template['kind'] ?? 'source-pending') === 'source-pending') {
                        $add('task', 'checks', 'Task source not loaded: '.$task->reference, null, $url);

                        continue;
                    }
                    if (! $task->due_date) {
                        $add('task', 'checks', 'Task deadline not recorded: '.$task->reference, null, $url);

                        continue;
                    }
                    if ($task->due_date->lte(today())) {
                        $state = TaskAvailability::for($task, $blockReleases[$task->id] ?? null);
                        $add('task', ! $state['available'] ? 'checks' : ($task->due_date->lt(today()) ? 'overdue' : 'today'), (! $state['available'] ? $state['label'].'; check the plan: ' : 'Task due: ').$task->reference, $task->due_date->toDateString(), $url);
                    }
                }
                foreach ($actions->get($a->id, collect()) as $x) {
                    if (! in_array($x->currentStatus(), ['To do', 'With tutor'], true)) {
                        continue;
                    }$date = $x->deadline()?->toDateString();
                    if (! $date || $date <= today()->toDateString()) {
                        $add('action', ! $date ? 'checks' : ($date < today()->toDateString() ? 'overdue' : 'today'), 'Agreed action: '.$x->title, $date, route('actions.index', $a).'#action-'.$x->id);
                    }
                }
                $sessions = $attendanceByApprentice[$a->id] ?? [];
                $report = StaffTrainingRecords::report($sessions);
                $pair = collect($report['pairs'])->last();
                $attendanceUrl = route('attendance.index');
                if ($pair) {
                    $add('attendance', 'overdue', 'Recorded non-attendance in two consecutive completed weeks (holiday/sickness/kept-at-work included)', $pair['to'], $attendanceUrl);
                } else {
                    foreach ($sessions as $s) {
                        if ($s['ended'] && $s['state'] !== 'Cancelled' && $s['date'] >= today()->subDays(14)->toDateString() && in_array($s['code'], ['A', 'H', 'S', 'W'], true)) {
                            $add('attendance', 'followup', 'Recorded missed session: '.AttendanceMath::CODES[$s['code']], $s['date'], $attendanceUrl);
                        }
                    }
                }
                foreach ($sessions as $s) {
                    if ($s['state'] !== 'Cancelled' && (! $s['valid'] || ($s['ended'] && ! $s['code']))) {
                        $add('attendance', 'checks', 'Saved roster/register needs checking; not assumed absence', $s['date'], $attendanceUrl);
                    }
                }
                if ($own->isNotEmpty() && $own->where('status', 'Approved')->count() < $own->count()) {
                    $dates = $approvedSummary[$a->id]['dates'] ?? [];
                    $unknown = $approvedSummary[$a->id]['unknown'] ?? false;
                    if ($unknown || ! $a->start_date || $a->start_date->gt(today())) {
                        $add('progress', 'checks', 'Task-approval/start dates need checking before a progress-gap flag', null, route('learning.index', $a));
                    } else {
                        $last = $dates ? max($dates) : $a->start_date->toDateString();
                        if ($last <= today()->subDays(30)->toDateString()) {
                            $add('progress', 'followup', 'No dated task approval for at least 30 days; check actual progress', $last, route('learning.index', $a));
                        }
                    }
                }
            }
            foreach ($entries->get($a->id, collect()) as $e) {
                $achievement = $e->kind === 'achievement';
                $add($achievement ? 'achievement' : 'help', 'followup', $achievement ? 'Workplace achievement awaiting check' : 'Help request awaiting first staff response', $e->created_at->toDateString(), route('experience.index', ['filter' => 'all']).'#entry-'.$e->id);
            }
            foreach ($employerReplies->get($a->id, collect()) as $reply) {
                $add('employer', 'followup', 'Employer reply awaiting staff check', $reply->responded_at?->toDateString(), route('employer-actions.staff', $a).'#employer-action-'.$reply->id);
            }
            if (! $reasons) {
                continue;
            }
            usort($reasons, fn ($x, $y) => [self::RANK[$x['bucket']], $x['date'] ?? '9999', $x['kind'], $x['text']] <=> [self::RANK[$y['bucket']], $y['date'] ?? '9999', $y['kind'], $y['text']]);
            $rows[] = ['apprentice' => $a, 'reasons' => $reasons, 'bucket' => $reasons[0]['bucket']];
        }
        usort($rows, fn ($x, $y) => [self::RANK[$x['bucket']], $x['reasons'][0]['date'] ?? '9999', $x['apprentice']->reference, $x['apprentice']->id] <=> [self::RANK[$y['bucket']], $y['reasons'][0]['date'] ?? '9999', $y['apprentice']->reference, $y['apprentice']->id]);
        $counts = ['all' => count($rows)];
        foreach (array_keys(self::RANK) as $bucket) {
            $counts[$bucket] = count(array_filter($rows, fn ($row) => collect($row['reasons'])->contains('bucket', $bucket)));
        }

        return ['rows' => collect($rows), 'counts' => $counts, 'scope_count' => $people->count()];
    }
}
