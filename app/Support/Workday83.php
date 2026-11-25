<?php

namespace App\Support;

use App\Models\{Apprentice, LearningTask, SmartAction, User};
use Illuminate\Support\Collection;

/** Navigation over existing records; never creates a second task or marks work complete. */
final class Workday83
{
    public static function combine(User $actor, Collection $training, ?array $reviews, ?array $registers): array
    {
        $rows = $training->values();
        foreach ($reviews['rows'] ?? [] as $row) {
            if (!in_array($row['bucket'], ['overdue', 'today', 'checks'], true)) continue;
            $a = $row['apprentice'];
            $rows->push(['key'=>'review:'.$a->id, 'kind'=>'review', 'bucket'=>$row['bucket'],
                'title'=>'Progress review', 'apprentice'=>$a->first_name.' '.$a->last_name, 'reference'=>$a->reference,
                'date'=>$row['due'], 'owner'=>$a->tutor?->name ?? 'Check assignment', 'reason'=>null,
                'url'=>PortalActions::destination($actor, $a, 'reviews')]);
        }
        foreach ($registers['rows'] ?? [] as $row) {
            $s = $row['session'];
            if (!$row['can_open'] || !in_array($row['bucket'], ['needs-register', 'checks'], true)) continue;
            $rows->push(['key'=>'register:'.$s->id, 'kind'=>'register', 'bucket'=>$row['bucket']==='checks'?'checks':'overdue',
                'title'=>$s->name, 'apprentice'=>$s->group->name, 'reference'=>'Register '.$s->session_date->toDateString(),
                'date'=>$s->session_date->toDateString(), 'owner'=>$s->group->tutor?->name ?? 'Check assignment',
                'reason'=>$row['bucket']==='checks'?'Saved register needs checking':$row['missing_codes'].' attendance codes missing',
                'url'=>route('attendance.show',$s)]);
        }
        $rank=['overdue'=>0,'today'=>1,'checks'=>2];
        $rows=$rows->unique('key')->sortBy(fn($row)=>[$rank[$row['bucket']],$row['date']??'9999',$row['key']])->values();
        $counts=['all'=>$rows->count()];foreach(array_keys($rank) as $bucket)$counts[$bucket]=$rows->where('bucket',$bucket)->count();
        return ['rows'=>$rows,'counts'=>$counts];
    }

    public static function nextRegister(User $actor, ?int $except=null, array $scope=[]): ?array
    {
        $access=RegisterFollowup::visible($actor, includeInactive:true);
        $sessions=$access['sessions'];
        if (!empty($scope['group'])) $sessions=$sessions->where('tutor_group_id',(int)$scope['group']);
        if (!empty($scope['apprentice'])) $sessions=$sessions->filter(fn($s)=>in_array((int)$scope['apprentice'],RegisterFollowup::roster($s)['ids'],true));
        if (filled($scope['search']??null)) $sessions=$sessions->filter(fn($s)=>mb_stripos($s->name,$scope['search'])!==false || mb_stripos($s->group->name,$scope['search'])!==false);
        return RegisterFollowup::for($sessions)['rows']->first(fn($row)=>
            $row['session']->id!==$except && $row['can_open'] &&
            ($row['bucket']==='needs-register' || ($row['bucket']==='not-ended' && $row['session']->session_date->isToday())));
    }

    public static function registerScope(mixed $input): array
    {
        abort_unless(is_array($input),422);
        return \Illuminate\Support\Facades\Validator::make($input,[
            'group'=>['nullable','integer','min:1'],'apprentice'=>['nullable','integer','min:1'],
            'search'=>['nullable','string','max:250'],
        ])->validate();
    }

    public static function apprentice(User $actor, Apprentice $a): array
    {
        abort_unless(LearningAccess::training($actor,$a),403);
        $tasks=LearningTask::with('apprentice')->where('apprentice_id',$a->id)->get();
        $actions=SmartAction::with(['task','apprentice'])->where('apprentice_id',$a->id)->get();
        $priorities=DashboardPriorities::for($actor,collect([$a]),$tasks,$actions);
        $attendance=null;$attendanceCheck=false;
        try{$attendance=AttendanceLedger::forIds([$a->id],false)[$a->id]['report'];}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){if($e->getStatusCode()!==422)throw $e;$attendanceCheck=true;}
        $reviews=$actor->role==='apprentice'?null:ReviewFollowup::for($actor,collect([$a]));
        $work=self::combine($actor,$priorities['rows'],$reviews,null);
        return ['task_total'=>$tasks->count(),'task_approved'=>$tasks->where('status','Approved')->count(),
            'attendance'=>$attendance,'attendance_check'=>$attendanceCheck,
            'review_due'=>SignedReviews::cycle($a)['next_due']??null,'next'=>$work['rows']->first(),
            'outstanding'=>$work['rows']->count()];
    }
}
