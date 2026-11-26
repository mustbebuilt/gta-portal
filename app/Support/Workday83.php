<?php

namespace App\Support;

use App\Models\{Apprentice, LearningTask, SmartAction, User};
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/** Navigation over existing records; never creates a second task or marks work complete. */
final class Workday83
{
    public const STATUSES = ['all'=>'All listed work', 'overdue'=>'Overdue', 'today'=>'Due today', 'checks'=>'Needs checking'];
    public const TYPES = ['all'=>'All work types', 'marking'=>'Marking', 'task'=>'Training tasks',
        'action'=>'SMART actions', 'review'=>'Progress reviews', 'register'=>'Registers'];

    public static function types(User $actor): array
    {
        return $actor->role === 'apprentice' ? Arr::only(self::TYPES, ['all', 'task', 'action']) : self::TYPES;
    }

    public static function scope(array $input, User $actor): array
    {
        $scope = [];
        $fields = $actor->role === 'apprentice' ? ['priority_page'] : ['group', 'apprentice', 'priority_page'];
        foreach ($fields as $field) {
            $value = filter_var($input[$field] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if ($value !== false && ($field !== 'priority_page' || $value <= 1000000)) {
                $scope[$field] = $value;
            }
        }
        foreach (['priority'=>self::STATUSES, 'work_type'=>self::types($actor)] as $field => $options) {
            $value = $input[$field] ?? null;
            if (is_string($value) && $value !== 'all' && isset($options[$value])) {
                $scope[$field] = $value;
            }
        }

        return $scope;
    }

    public static function returnParameters(Request $request): array
    {
        $input = $request->query('work_today');
        $actor = $request->user();
        if (! is_array($input) || ($input['from'] ?? null) !== '1' || ! $actor
            || ! in_array($actor->role, ['admin', 'ceo', 'tutor', 'apprentice'], true)) {
            return [];
        }

        return ['work_today' => ['from'=>'1', ...self::scope($input, $actor)]];
    }

    public static function returnUrl(Request $request): ?string
    {
        $context = self::returnParameters($request);

        return $context ? route('dashboard.index', Arr::except($context['work_today'], ['from'])).'#training-priorities' : null;
    }

    public static function openItem(array $item, array $scope): string
    {
        [$url, $fragment] = array_pad(explode('#', $item['url'], 2), 2, null);
        $localScope = match ($item['kind']) {
            'register' => Arr::only($scope, ['group', 'apprentice']),
            'marking' => ['from_marking'=>1],
            default => [],
        };

        return $url.(str_contains($url, '?') ? '&' : '?')
            .Arr::query([...$localScope, 'work_today'=>['from'=>'1', ...$scope]])
            .($fragment !== null ? '#'.$fragment : '');
    }

    public static function counts(Collection $rows): array
    {
        $byStatus = $rows->countBy('bucket');

        return ['all'=>$rows->count(), 'overdue'=>$byStatus->get('overdue', 0),
            'today'=>$byStatus->get('today', 0), 'checks'=>$byStatus->get('checks', 0)];
    }

    public static function combine(User $actor, Collection $training, ?array $reviews, ?array $registers): array
    {
        $rows = $training->values();
        foreach ($reviews['rows'] ?? [] as $row) {
            if (!in_array($row['bucket'], ['overdue', 'today', 'checks'], true)) continue;
            $a = $row['apprentice'];
            $rows->push(['key'=>'review:'.$a->id, 'kind'=>'review', 'bucket'=>$row['bucket'],
                'title'=>'Progress review', 'apprentice'=>$a->first_name.' '.$a->last_name, 'reference'=>$a->reference,
                'date'=>$row['due'], 'sort'=>$row['due'], 'owner'=>$a->tutor?->name ?? 'Check assignment',
                'reason'=>$row['bucket'] === 'checks' ? 'Check the review record and next date' : null,
                'url'=>PortalActions::destination($actor, $a, 'reviews')]);
        }
        foreach ($registers['rows'] ?? [] as $row) {
            $s = $row['session'];
            if (!$row['can_open'] || !in_array($row['bucket'], ['needs-register', 'checks'], true)) continue;
            $bucket = $row['bucket'] === 'checks' ? 'checks' : ($s->session_date->isToday() ? 'today' : 'overdue');
            $date = $s->session_date->toDateString();
            $rows->push(['key'=>'register:'.$s->id, 'kind'=>'register', 'bucket'=>$bucket,
                'title'=>$s->name, 'apprentice'=>$s->group->name, 'reference'=>'Register '.$date,
                'date'=>$date, 'sort'=>$date, 'owner'=>$s->group->tutor?->name ?? 'Check assignment',
                'reason'=>$row['bucket']==='checks'?'Saved register needs checking':$row['missing_codes'].' attendance codes missing',
                'url'=>route('attendance.show',$s)]);
        }
        $rank=['overdue'=>0,'today'=>1,'checks'=>2];
        $rows = $rows->unique('key')->sortBy(fn ($row) => [$rank[$row['bucket']], ($row['sort'] ?? '') ?: '9999', $row['key']])->values();

        return ['rows'=>$rows, 'counts'=>self::counts($rows)];
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
