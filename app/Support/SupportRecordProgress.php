<?php

namespace App\Support;

use App\Models\{Apprentice, LearningTask, SmartAction, User};

final class SupportRecordProgress
{
    public static function forApprentice(User $user, Apprentice $apprentice): array
    {
        $through = today()->toDateString();
        $from = today()->subDays(83)->toDateString();
        $ledger = AttendanceLedger::reportPeriod([$apprentice->id], $from, $through);
        $attendance = $ledger === null ? null : AttendanceMath::report($ledger[$apprentice->id]['rows'] ?? []);
        $otj = OtjPosition::headline(OtjPosition::forApprentice($apprentice));

        $blocks = LearningTask::where('apprentice_id', $apprentice->id)
            ->whereBetween('block', [1, 10])->orderBy('block')->get(['block', 'status'])->groupBy('block');
        $blockNumber = $blocks->search(fn ($tasks) => $tasks->contains(fn ($task) => $task->status !== 'Approved'));
        $block = $blockNumber === false ? null : [
            'number' => $blockNumber,
            'allocated' => $blocks[$blockNumber]->count(),
            'approved' => $blocks[$blockNumber]->where('status', 'Approved')->count(),
        ];

        $otherActions = null;
        if (LearningAccess::training($user, $apprentice)) {
            $actions = SmartAction::with('task:id,status,due_date')->where('apprentice_id', $apprentice->id)->orderBy('id')->get();
            [$completed, $planned] = $actions->partition(fn ($action) => $action->currentStatus() === 'Completed');
            $otherActions = [
                'planned' => $planned->sortBy(fn ($action) => $action->deadline()?->toDateString() ?? '9999-12-31'),
                'completed' => $completed->reverse(),
            ];
        }

        return [
            'attendance' => $attendance, 'otj' => $otj, 'block' => $block, 'allApproved' => $blocks->isNotEmpty() && $block === null,
            'otherActions' => $otherActions,
        ];
    }
}
