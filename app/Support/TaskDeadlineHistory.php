<?php
namespace App\Support;
use App\Models\{Apprentice,LearningTask,User};

class TaskDeadlineHistory
{
    // Caller owns the write transaction and task/apprentice locks.
    public static function actor(User $user, int $version, Apprentice $apprentice, bool $managementOnly=false): User
    {
        $fresh=User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        abort_unless($fresh->auth_version===$version && (LearningAccess::manager($fresh)||(!$managementOnly&&LearningAccess::tutor($fresh,$apprentice))),403);
        return $fresh;
    }

    public static function record(LearningTask $task, ?string $date, User $actor, string $origin, ?string $reason=null, array $source=[]): bool
    {
        if(!in_array($origin,['manual','approved_plan'],true)) {throw new \InvalidArgumentException('Unknown deadline origin.');}
        $before=$task->due_date?->toDateString();
        if($before===$date) {return false;}
        $history=$task->deadline_history??[];
        $history[]=['before'=>$before,'after'=>$date,'by'=>$actor->id,'by_name'=>$actor->name,'at'=>now()->toIso8601String(),
            'origin'=>$origin,'reason'=>$reason,'source'=>$source,'before_version'=>$task->version,'after_version'=>$task->version+1];
        $task->deadline_history=$history;$task->due_date=$date;
        return true;
    }
}
