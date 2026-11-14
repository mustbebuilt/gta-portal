<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\LearningTask;
use App\Models\SmartAction;

class ActionProposals
{
    public static function binding(LearningTask $task): string
    {
        return hash('sha256',json_encode([$task->id,$task->apprentice_id,$task->version,$task->status,$task->due_date?->toDateString(),$task->template['sha256']??null,collect($task->submissions??[])->last()],JSON_THROW_ON_ERROR));
    }

    public static function forApprentice(Apprentice $a): array
    {
        $actions=SmartAction::where('apprentice_id',$a->id)->whereNotNull('learning_task_id')->get()->keyBy('learning_task_id');
        $tasks=LearningTask::where('apprentice_id',$a->id)->where('status','Referred')->orderBy('block')->orderBy('reference')->get();
        $corrections=$tasks->map(fn($t)=>['task'=>$t,'action'=>$actions->get($t->id),'binding'=>self::binding($t),'feedback'=>collect($t->submissions??[])->last()['decision']['feedback']??null]);
        $next=LearningTask::where('apprentice_id',$a->id)->whereNotIn('status',['Approved','Referred','Source required'])->whereNotNull('due_date')->whereDate('due_date','>=',today())->whereDate('due_date','<=',today()->addDays(70))->orderBy('due_date')->get();

        return ['corrections'=>$corrections,'nextTasks'=>$next];
    }
}
