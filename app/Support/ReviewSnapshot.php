<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\LearningTask;
use App\Models\MonthlyScorecard;
use App\Models\Qualification;
use App\Models\SmartAction;
use App\Models\TrainingSession;
use App\Models\TrainingTopic;
use Carbon\Carbon;

class ReviewSnapshot
{
    public static function capture(Apprentice $a, array $fields): array
    {
        $plan = DeliveryPlan::approved($a);
        $tasks = LearningTask::where('apprentice_id', $a->id)->get();
        $topics = TrainingTopic::where('apprentice_id', $a->id)->get();
        $qualifications = Qualification::where('apprentice_id', $a->id)->get();
        $delivered = [];
        $planned = [];
        if (filled($fields['reviewDate'] ?? null)) {
            $end = Carbon::parse($fields['reviewDate'])->addDays(70)->toDateString();
            $sessions = TrainingSession::with(['marks' => fn ($q) => $q->where('apprentice_id', $a->id)])->whereJsonContains('members', $a->id)->where('state', '!=', 'Cancelled')->orderBy('session_date')->get();
            foreach ($sessions as $s) {
                $date = $s->session_date->toDateString();
                $row = ['date' => $date, 'name' => $s->name, 'subjects' => $s->delivered_learning];
                if (filled($fields['trainingFrom'] ?? null) && $date >= $fields['trainingFrom'] && $date <= $fields['reviewDate'] && $s->ended() && in_array($s->marks->first()?->code, ['O', 'T'], true)) {
                    $delivered[] = $row;
                }if ($date > $fields['reviewDate'] && $date <= $end) {
                    $planned[] = ['date' => $date, 'name' => $s->name, 'subjects' => $s->planned_learning];
                }
            }
        }

        $monthly = MonthlyScorecard::where('apprentice_id', $a->id)
            ->when(filled($fields['trainingFrom'] ?? null), fn ($q) => $q->where('month', '>=', substr($fields['trainingFrom'], 0, 7)))
            ->when(filled($fields['reviewDate'] ?? null), fn ($q) => $q->where('month', '<=', substr($fields['reviewDate'], 0, 7)))
            ->orderBy('month')->get()->map(static function (MonthlyScorecard $row): array {
                $entry = $row->only(['month', 'scores', 'comment']);
                $development = MonthlyDevelopment::read($row);
                if ($development !== null) {
                    $entry['development'] = ['ratings' => $development['ratings'], 'comment' => $development['comment']];
                }

                return $entry;
            })->keyBy('month')->all();

        if (filled($fields['trainingFrom'] ?? null) && filled($fields['reviewDate'] ?? null)) {
            $months = ApprenticeReviewData::months($fields['trainingFrom'], $fields['reviewDate'], $monthly);
            $monthly = array_map(static fn (array $month): array => $month['record'] ?? [
                'month' => $month['month'],
                'missing' => true,
            ], $months);
        }

        $trainingFrom = $fields['trainingFrom'] ?? null;
        $meeting = $fields['reviewDate'] ?? null;
        $scope = $plan['curriculum'] ?? [];
        $taskScope = isset($scope['tasks']) ? array_keys($scope['tasks']) : null;
        $assignedTasks = $tasks->filter(fn ($task) => ($task->template['kind'] ?? 'source-pending') !== 'source-pending');
        $completedTasks = $assignedTasks->where('status', 'Approved')->filter(fn ($task) =>
            $taskScope === null || TaskCatalogue::savedKey($task) === $plan['identity']['catalogue']);
        $progress = ['Apprenticeship tasks'=>self::progress(
            $completedTasks->pluck('reference')->all(), $assignedTasks->pluck('reference')->all(), $taskScope
        )];
        foreach (PlanCurriculum::SUBJECTS as $key => $label) {
            $assigned = $topics->where('subject', $key)->filter(fn ($topic) => $topic->planned_hours > 0);
            $secureIds = $topics->where('subject', $key)->filter(fn ($topic) => (float) $topic->planned_hours === 0.0)
                ->map(fn ($topic) => PlanCurriculum::keyForTopic($topic))->all();
            $required = $scope['subjects'][$key]['topics'] ?? null;
            $required = $required === null ? null : array_diff(array_keys($required), $secureIds);
            $topicId = fn ($topic) => PlanCurriculum::keyForTopic($topic);
            $progress[$label] = self::progress($assigned->where('status', 'Completed')->map($topicId)->all(),
                $assigned->map($topicId)->all(), $required);
        }
        $attendance = null;
        $otj = OtjPosition::forApprentice($a);
        $periodOtjMinutes = null;
        if ($trainingFrom && $meeting) {
            $ledger = AttendanceLedger::forIds([$a->id], false)[$a->id]['rows'];
            $attendance = RetentionAnalysis::registerAttendance($ledger, $trainingFrom, $meeting);
            $periodRows = array_filter($otj['rows'], fn ($row) => !empty($row['date'])
                && $row['date'] >= $trainingFrom && $row['date'] <= $meeting);
            $periodOtjMinutes = array_sum(array_column($periodRows, 'minutes'));
        }
        $outstanding = $meeting ? $tasks->filter(fn ($task) => $task->status !== 'Approved'
            && $task->due_date && $task->due_date->toDateString() <= $end)
            ->map(fn ($task) => ['id'=>$task->id,'title'=>$task->title,'block'=>$task->block,
                'due_date'=>$task->due_date->toDateString()])->sortBy('due_date')->values()->all() : [];

        $programmeDates = [
            'start' => $plan['identity']['start'] ?? $a->start_date?->toDateString(),
            'practical_end' => $plan['details']['practical_end_date'] ?? null,
            'apprenticeship_end' => $plan['details']['apprenticeship_end_date'] ?? null,
        ];

        return ['review_summary' => ['progress'=>$progress,'attendance'=>$attendance,'period_otj_minutes'=>$periodOtjMinutes,'outstanding'=>$outstanding,
            'training_receipts'=>$trainingFrom && $meeting ? array_values(array_filter(RegisterTraining::receipts($a->id, $meeting),
                fn ($receipt) => $receipt['date'] >= $trainingFrom)) : [],
            'next_due'=>$meeting ? Carbon::parse($meeting)->addDays(70)->toDateString() : null],
            'requires_safety_answer' => true, 'formal_ksb_results' => FormalKsbResults::capture($a, $plan, $meeting), 'programme_dates' => $programmeDates, 'captured_at' => now()->toIso8601String(), 'otj_position' => $otj['summary'], 'scope_note' => 'Current source records captured when this draft was saved; not a reconstructed historical whole-plan assessment.', 'whole_plan' => $plan ? 'Provider delivery plan linked; participant signatures and formal KSB achievement remain separate' : 'Not linked — individual plan applicability and KSB/block coverage cannot be confirmed', 'delivery_plan' => $plan ? ['hash' => $plan['hash'], 'approved_at' => $plan['approved_at'], 'source_ref' => $plan['details']['source_ref'], 'target_hours' => $plan['details']['target_hours'], 'blocks' => $plan['blocks'], 'next_period_blocks' => filled($fields['reviewDate'] ?? null) ? array_values(array_filter($plan['blocks'], fn ($b) => $b['end'] > $fields['reviewDate'] && $b['start'] <= Carbon::parse($fields['reviewDate'])->addDays(70)->toDateString())) : []] : null, 'approved_tasks' => $tasks->where('status', 'Approved')->count(), 'assigned_tasks' => $tasks->count(), 'blocks' => $tasks->groupBy('block')->map(fn ($rows) => ['title' => $rows->first()->theme, 'approved' => $rows->where('status', 'Approved')->count(), 'assigned' => $rows->count()])->all(), 'topics' => $topics->map(fn ($t) => $t->only('subject', 'level', 'topic', 'planned_hours', 'status', 'completion_date'))->all(), 'qualifications' => $qualifications->map(fn ($q) => [...$q->only('subject', 'title', 'level', 'status', 'result'), 'target' => $q->target()?->toDateString()])->all(), 'delivered' => $delivered, 'planned' => $planned, 'forecast_complete' => false, 'monthly_snapshots' => array_values($monthly),
            'action_plan' => SmartAction::with('task')->where('apprentice_id', $a->id)->get()->map(fn ($action) => ['id' => $action->id, 'title' => $action->title, 'owner' => $action->owner, 'status' => $action->currentStatus(), 'relevance' => $action->relevance, 'due_date' => $action->deadline()?->toDateString(), 'completion_date' => $action->completion_date?->toDateString(), 'task_id' => $action->learning_task_id])->all(),
            'known_next_period_tasks' => filled($fields['reviewDate'] ?? null) ? $tasks->filter(fn ($t) => $t->status !== 'Approved' && $t->due_date && $t->due_date->toDateString() >= $fields['reviewDate'] && $t->due_date->toDateString() <= $end)->map(fn ($t) => ['id' => $t->id, 'title' => $t->title, 'block' => $t->block, 'due_date' => $t->due_date->toDateString(), 'status' => $t->status])->values()->all() : [],
            'training_plan_source' => $plan ? $plan['details']['source_ref'] : 'Reference remains the supplied scheme of work; no substitute individual plan created'];
    }

    private static function progress(array $done, array $assigned, ?array $whole): array
    {
        $progress = ApprenticeReviewData::progress($done, $assigned, $whole);
        return ['done'=>$progress['assigned_done'], 'total'=>$progress['assigned_total'],
            'percent'=>$progress['assigned_percent'], 'whole_done'=>$progress['overall_done'],
            'whole_total'=>$progress['overall_total'], 'whole_percent'=>$progress['overall_percent']];
    }

}
