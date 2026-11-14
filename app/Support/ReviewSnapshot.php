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

        return ['captured_at' => now()->toIso8601String(), 'otj_position' => OtjPosition::forApprentice($a)['summary'], 'scope_note' => 'Current source records captured when this draft was saved; not a reconstructed historical whole-plan assessment.', 'whole_plan' => $plan ? 'Provider delivery plan linked; participant signatures and formal KSB achievement remain separate' : 'Not linked — individual plan applicability and KSB/block coverage cannot be confirmed', 'delivery_plan' => $plan ? ['hash' => $plan['hash'], 'approved_at' => $plan['approved_at'], 'source_ref' => $plan['details']['source_ref'], 'target_hours' => $plan['details']['target_hours'], 'blocks' => $plan['blocks'], 'next_period_blocks' => filled($fields['reviewDate'] ?? null) ? array_values(array_filter($plan['blocks'], fn ($b) => $b['end'] > $fields['reviewDate'] && $b['start'] <= Carbon::parse($fields['reviewDate'])->addDays(70)->toDateString())) : []] : null, 'approved_tasks' => $tasks->where('status', 'Approved')->count(), 'assigned_tasks' => $tasks->count(), 'blocks' => $tasks->groupBy('block')->map(fn ($rows) => ['title' => $rows->first()->theme, 'approved' => $rows->where('status', 'Approved')->count(), 'assigned' => $rows->count()])->all(), 'topics' => $topics->map(fn ($t) => $t->only('subject', 'level', 'topic', 'planned_hours', 'status', 'completion_date'))->all(), 'qualifications' => $qualifications->map(fn ($q) => [...$q->only('subject', 'title', 'level', 'status', 'result'), 'target' => $q->target()?->toDateString()])->all(), 'delivered' => $delivered, 'planned' => $planned, 'forecast_complete' => false, 'monthly_snapshots' => MonthlyScorecard::where('apprentice_id', $a->id)->when(filled($fields['trainingFrom'] ?? null), fn ($q) => $q->where('month', '>=', substr($fields['trainingFrom'], 0, 7)))->when(filled($fields['reviewDate'] ?? null), fn ($q) => $q->where('month', '<=', substr($fields['reviewDate'], 0, 7)))->get(['month', 'scores', 'comment'])->toArray(),
            'action_plan' => SmartAction::with('task')->where('apprentice_id', $a->id)->get()->map(fn ($action) => ['id' => $action->id, 'title' => $action->title, 'owner' => $action->owner, 'status' => $action->currentStatus(), 'due_date' => $action->deadline()?->toDateString(), 'completion_date' => $action->completion_date?->toDateString(), 'task_id' => $action->learning_task_id])->all(),
            'known_next_period_tasks' => filled($fields['reviewDate'] ?? null) ? $tasks->filter(fn ($t) => $t->status !== 'Approved' && $t->due_date && $t->due_date->toDateString() >= $fields['reviewDate'] && $t->due_date->toDateString() <= $end)->map(fn ($t) => ['id' => $t->id, 'title' => $t->title, 'block' => $t->block, 'due_date' => $t->due_date->toDateString(), 'status' => $t->status])->values()->all() : [],
            'training_plan_source' => $plan ? $plan['details']['source_ref'] : 'Reference remains the supplied scheme of work; no substitute individual plan created'];
    }
}
