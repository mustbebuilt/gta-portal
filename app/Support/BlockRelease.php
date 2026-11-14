<?php

namespace App\Support;

use App\Models\LearningBlockControl;
use App\Models\LearningTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BlockRelease
{
    public static function state(LearningTask $task): array
    {
        $control = LearningBlockControl::where('apprentice_id', $task->apprentice_id)->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->first();
        if (! $control?->enabled) {
            return ['open' => true, 'label' => 'Sequence control not enabled'];
        }
        if ($task->block === 1) {
            return ['open' => true, 'label' => 'First training block'];
        }
        if (isset(($control->exceptions ?? [])[(string) $task->block])) {
            return ['open' => true, 'label' => 'Administrator release exception'];
        }
        if ($task->block < 1 || $task->block > 10) {
            return ['open' => false, 'label' => 'Block content not released'];
        }
        for ($block = 1; $block < $task->block; $block++) {
            $tasks = LearningTask::where('apprentice_id', $task->apprentice_id)->where('block', $block)->get();
            $expected = array_column(array_filter(TaskCatalogue::tasks(TaskCatalogue::savedKey($task)), fn ($t) => (int) $t['block'] === $block), 'id');
            if (count($expected) !== 5 || array_diff($expected, $tasks->pluck('reference')->all()) || $tasks->whereIn('reference', $expected)->filter(fn ($prior) => TaskCatalogue::savedKey($prior) === TaskCatalogue::savedKey($task) && $prior->template['kind'] !== 'source-pending' && $prior->status === 'Approved')->count() !== 5) {
                return ['open' => false, 'label' => 'Complete all five source tasks in Block '.$block.' first'];
            }
        }

return ['open' => true, 'label' => 'Previous training-block tasks approved'];
    }

    public static function check(LearningTask $task): void
    {
        $s = self::state($task);
        if (! $s['open']) {
            throw ValidationException::withMessages(['block' => $s['label'].'. An administrator can record a release exception.']);
        }
    }
}
