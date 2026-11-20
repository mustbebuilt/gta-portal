<?php

namespace App\Support;

use App\Models\{Apprentice, LearningTask, MonthlyScorecard, SignedReviewRecord, TrainingTopic};

/** Pack 74: Live data adapter for Apprentice Progress Review using ApprenticeReviewData. */
final class ProgressReview74
{
    public static function build(Apprentice $apprentice, ?string $meetingDate = null, ?string $asOf = null): array
    {
        $asOf ??= today()->toDateString();
        $start = $apprentice->start_date?->toDateString() ?? $asOf;

        $lastReview = SignedReviewRecord::where('apprentice_id', $apprentice->id)
            ->whereNotNull('checked_at')
            ->whereNull('withdrawn_at')
            ->orderByDesc('meeting_date')
            ->first();
        $lastMeeting = $lastReview?->meeting_date?->toDateString();

        $period = ApprenticeReviewData::period($lastMeeting, $start, $asOf, $meetingDate);
        $approvedPlan = DeliveryPlan::approved($apprentice);

        // Tasks progress (excluding source-pending)
        $allTasks = LearningTask::where('apprentice_id', $apprentice->id)->get();
        $validTasks = $allTasks->filter(fn ($t) => ($t->template['kind'] ?? null) !== 'source-pending');
        $assignedIds = $validTasks->pluck('id')->map(fn ($id) => (string) $id)->all();
        $approvedIds = $validTasks->where('status', 'Approved')->pluck('id')->map(fn ($id) => (string) $id)->all();
        $taskProgress = ApprenticeReviewData::progress($approvedIds, $assignedIds, $assignedIds ?: null);

        // English & Maths topics (excluding zero-hour secure topics)
        $topics = TrainingTopic::where('apprentice_id', $apprentice->id)->whereIn('subject', ['english', 'maths'])->get();
        $fsProgress = [];
        foreach (['english', 'maths'] as $subj) {
            $eligible = $topics->where('subject', $subj)->filter(fn ($t) => (float) ($t->planned_hours ?? 0) > 0);
            $aIds = $eligible->pluck('id')->map(fn ($id) => (string) $id)->all();
            $cIds = $eligible->where('status', 'Completed')->pluck('id')->map(fn ($id) => (string) $id)->all();
            $fsProgress[$subj] = ApprenticeReviewData::progress($cIds, $aIds, $aIds ?: null);
        }

        // Cross-group training delivery from Pack 72 personal training bank
        $journey = TrainingJourney72::forApprentice($apprentice, $period['to']);
        $receiptsForReview = array_map(fn ($r) => [
            'session_id' => (string) $r['sessionId'],
            'subject_id' => (string) $r['subjectId'],
            'date' => $r['date'],
            'area' => match ($r['area'] ?? 'vocational') {
                'functional_skills' => 'fs',
                'cross_cutting' => 'themes',
                default => 'training',
            },
            'label' => $r['label'] ?? $r['subjectId'],
            'group' => $r['group'] ?? '',
            'coverage' => $r['coverage'] ?? 'delivered',
            'attended' => true,
            'cancelled' => false,
            'valid' => true,
        ], $journey['receipts']);
        $delivered = ApprenticeReviewData::delivered($receiptsForReview, $period);

        // Tasks due / overdue / undated
        $taskRows = $allTasks->map(fn ($t) => [
            'id' => (string) $t->id,
            'title' => $t->title,
            'due' => $t->due_date?->toDateString(),
            'approved' => $t->status === 'Approved',
            'source_pending' => ($t->template['kind'] ?? null) === 'source-pending',
        ])->all();
        $tasks = ApprenticeReviewData::tasks($taskRows, $period['to'], $period['target_window_end']);

        // Monthly personal development snapshots across covered calendar months
        $scorecards = MonthlyScorecard::where('apprentice_id', $apprentice->id)->get()->keyBy('month');
        $monthRecords = [];
        foreach ($scorecards as $m => $card) {
            $dev = MonthlyDevelopment::read($card);
            $monthRecords[$m] = $dev ?? ['schema' => 'legacy-numeric', 'scores' => $card->scores, 'comment' => $card->comment];
        }
        $months = ApprenticeReviewData::months($period['from'], $period['to'], $monthRecords);

        return [
            'apprentice' => $apprentice,
            'period' => $period,
            'dates' => [
                'start' => $apprentice->start_date?->toDateString() ?? 'Not recorded',
                'practical_end' => $approvedPlan['details']['practical_end_date'] ?? 'Not recorded',
                'apprenticeship_end' => $apprentice->planned_end_date?->toDateString() ?? 'Not recorded',
                'actual_review' => $meetingDate ?? 'Draft only (not yet held)',
                'next_due' => $period['next_due'] ?? $period['scheduled_due'],
            ],
            'progress' => ['tasks' => $taskProgress, 'english' => $fsProgress['english'], 'maths' => $fsProgress['maths']],
            'delivered' => $delivered,
            'legacy_sessions' => $journey['legacy_sessions'],
            'tasks' => $tasks,
            'months' => $months,
            'areas' => ApprenticeReviewData::DEVELOPMENT_AREAS,
        ];
    }
}
