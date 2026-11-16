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

    /** Read-only request snapshot. Write checks continue to call state() and acquire their existing locks. */
    public static function states(\Illuminate\Support\Collection $tasks): array
    {
        if ($tasks->isEmpty()) { return []; }
        $controls = LearningBlockControl::whereIn('apprentice_id', $tasks->pluck('apprentice_id')->unique())->get()->keyBy('apprentice_id');
        $required = $tasks->filter(function ($task) use ($controls) {
            $control = $controls->get($task->apprentice_id);
            return $control?->enabled && $task->block > 1 && $task->block <= 10 && !isset(($control->exceptions ?? [])[(string)$task->block]);
        })->pluck('apprentice_id')->unique();
        $prior = $required->isEmpty() ? collect() : LearningTask::whereIn('apprentice_id', $required)->get(['id','apprentice_id','block','reference','status','template->catalogueId as source_catalogue','template->kind as source_kind'])->map(function ($row) {
            return ['apprentice_id'=>$row->apprentice_id,'block'=>$row->block,'reference'=>$row->reference,'status'=>$row->status,'catalogue'=>$row->source_catalogue===null?'autocare':(string)$row->source_catalogue,'kind'=>$row->source_kind];
        })->groupBy(fn ($row) => $row['apprentice_id'].':'.$row['block']);
        $catalogues = []; $out = []; $memo = [];
        foreach ($tasks as $task) {
            $memoKey=$task->apprentice_id.':'.$task->block.':'.TaskCatalogue::savedKey($task);
            if (isset($memo[$memoKey])) { $out[$task->id]=$memo[$memoKey]; continue; }
            $control = $controls->get($task->apprentice_id);
            if (!$control?->enabled) { $state = ['open'=>true,'label'=>'Sequence control not enabled']; }
            elseif ($task->block === 1) { $state = ['open'=>true,'label'=>'First training block']; }
            elseif (isset(($control->exceptions ?? [])[(string)$task->block])) { $state = ['open'=>true,'label'=>'Administrator release exception']; }
            elseif ($task->block < 1 || $task->block > 10) { $state = ['open'=>false,'label'=>'Block content not released']; }
            else {
                $key = TaskCatalogue::savedKey($task);
                $catalogues[$key] ??= collect(TaskCatalogue::tasks($key))->groupBy('block')->map(fn ($rows) => $rows->pluck('id')->all())->all();
                $state = ['open'=>true,'label'=>'Previous training-block tasks approved'];
                for ($block=1; $block<$task->block; $block++) {
                    $rows = $prior->get($task->apprentice_id.':'.$block, collect());
                    $expected = $catalogues[$key][$block] ?? [];
                    if (count($expected)!==5 || array_diff($expected,$rows->pluck('reference')->all()) || $rows->whereIn('reference',$expected)->filter(fn ($row) => $row['catalogue']===$key && $row['kind']!=='source-pending' && $row['status']==='Approved')->count()!==5) {
                        $state = ['open'=>false,'label'=>'Complete all five source tasks in Block '.$block.' first']; break;
                    }
                }
            }
            $out[$task->id] = $memo[$memoKey] = $state;
        }
        return $out;
    }

    public static function check(LearningTask $task): void
    {
        $s = self::state($task);
        if (! $s['open']) {
            throw ValidationException::withMessages(['block' => $s['label'].'. An administrator can record a release exception.']);
        }
    }
}
