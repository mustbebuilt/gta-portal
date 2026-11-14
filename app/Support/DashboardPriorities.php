<?php
namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;

class DashboardPriorities
{
    // A read-only view of existing training deadlines, not a new risk/approval policy.
    public static function for(User $user,Collection $people,Collection $tasks,Collection $actions): array
    {
        $people=$people->filter(fn($a)=>LearningAccess::training($user,$a))->keyBy('id');$rows=[];
        $add=function(string $key,string $bucket,string $title,string $owner,$date,string $url,$person,string $kind)use(&$rows): void {
            $rows[]=['key'=>$key,'bucket'=>$bucket,'title'=>$title,'owner'=>$owner,'date'=>$date?->format($kind==='marking'?'d M Y H:i':'d M Y'),'sort'=>$date?->format('Y-m-d H:i:s')??'','url'=>$url,'apprentice'=>$person->first_name.' '.$person->last_name,'reference'=>$person->reference,'kind'=>$kind];
        };
        foreach($tasks as $task){
            $person=$people->get($task->apprentice_id);if(!$person){continue;}
            $url=route('tasks.show',$task);$title=$task->reference.': '.$task->title;
            if($task->status==='Awaiting marking'){
                if($user->role==='apprentice'){continue;}
                if(!$task->submitted_at || $task->submitted_at->gt(now())){$add('task-'.$task->id,'checks',$title,'Main tutor',null,$url,$person,'marking');}
                elseif($task->markingOverdue()){$add('task-'.$task->id,'overdue',$title,'Main tutor',$task->submitted_at->copy()->addDays(7),$url,$person,'marking');}
                elseif($task->submitted_at->copy()->addDays(7)->isToday()){$add('task-'.$task->id,'today',$title,'Main tutor',$task->submitted_at->copy()->addDays(7),$url,$person,'marking');}
                continue;
            }
            if(!$task->editable()){continue;}
            if(!$task->due_date){$add('task-'.$task->id,'checks',$title,'Main tutor',null,$url,$person,'task');}
            elseif($task->due_date->lt(today())){$add('task-'.$task->id,'overdue',$title,'Apprentice',$task->due_date,$url,$person,'task');}
            elseif($task->due_date->isToday()){$add('task-'.$task->id,'today',$title,'Apprentice',$task->due_date,$url,$person,'task');}
        }
        foreach($actions as $action){
            $person=$people->get($action->apprentice_id);
            // Linked actions have no separate row; their scoped task carries the work/deadline.
            if(!$person || $action->learning_task_id || $action->currentStatus()==='Completed'){continue;}
            if(!in_array($action->currentStatus(),['To do','With tutor'],true)){continue;}
            if($user->role==='apprentice' && ($action->owner!=='Apprentice' || $action->currentStatus()!=='To do')){continue;}
            $date=$action->deadline();$bucket=!$date?'checks':($action->overdue()?'overdue':($date->isToday()?'today':null));
            if($bucket){$add('action-'.$action->id,$bucket,$action->title,$action->currentStatus()==='With tutor'?'Main tutor':$action->owner,$date,route('actions.index',$person).'#action-'.$action->id,$person,'action');}
        }
        $rank=['overdue'=>0,'today'=>1,'checks'=>2];usort($rows,fn($a,$b)=>[$rank[$a['bucket']],$a['sort'],$a['key']]<=>[$rank[$b['bucket']],$b['sort'],$b['key']]);
        $rows=collect($rows);return ['rows'=>$rows,'counts'=>['all'=>$rows->count(),'overdue'=>$rows->where('bucket','overdue')->count(),'today'=>$rows->where('bucket','today')->count(),'checks'=>$rows->where('bucket','checks')->count()]];
    }
}
