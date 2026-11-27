<?php

namespace App\Support;

use App\Models\{Apprentice, LearningTask, SmartAction, TrainingSession, TrainingTopic, TutorGroup, User};
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
                'title'=>'Progress review', 'apprentice_id'=>$a->id, 'apprentice'=>$a->first_name.' '.$a->last_name, 'reference'=>$a->reference,
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

    public static function nextRegister(User $actor, ?int $except = null, array $scope = []): ?array
    {
        abort_unless($actor->active && (LearningAccess::manager($actor) || $actor->role === 'tutor'), 403);
        $manager = LearningAccess::manager($actor);
        $groups = TutorGroup::where(fn ($q) => $q->where('active', true)->orWhereNotNull('weekly_register'))
            ->when(! $manager, fn ($q) => $q->where('tutor_id', $actor->id))
            ->when(! empty($scope['group']), fn ($q) => $q->whereKey((int) $scope['group']))
            ->orderBy('name')
            ->get(['id', 'name', 'tutor_id', 'active', 'weekly_register']);
        if ($groups->isEmpty()) {
            return null;
        }

        $today = today()->toDateString();
        $search = filled($scope['search'] ?? null) ? $scope['search'] : null;
        $apprenticeId = ! empty($scope['apprentice']) ? (int) $scope['apprentice'] : null;
        $query = TrainingSession::whereIn('tutor_group_id', $groups->pluck('id'))
            ->where('state', 'Planned')
            ->whereDate('session_date', '<=', $today)
            ->when($except !== null, fn ($q) => $q->where('id', '!=', $except))
            ->orderBy('session_date')
            ->orderBy('start_time')
            ->orderBy('id')
            ->select(['id', 'tutor_group_id', 'name', 'session_date', 'start_time', 'end_time', 'state', 'members', 'version']);

        $groupsById = $groups->keyBy('id');
        $firstNeedsRegister = null;
        $firstTodayNotEnded = null;
        $knownCache = [];

        $query->chunk(200, function (Collection $chunk) use (
            $actor, $groupsById, $search, $apprenticeId, &$firstNeedsRegister, &$firstTodayNotEnded, &$knownCache
        ): bool {
            $chunk->each(fn (TrainingSession $s) => $s->setRelation('group', $groupsById->get($s->tutor_group_id)));
            $filtered = $chunk->filter(function (TrainingSession $s) use ($actor, $search, $apprenticeId): bool {
                if (! RegisterFollowup::canOpen($actor, $s)) {
                    return false;
                }
                if ($apprenticeId !== null && ! in_array($apprenticeId, RegisterFollowup::roster($s)['ids'], true)) {
                    return false;
                }
                if ($search !== null && mb_stripos($s->name, $search) === false && mb_stripos($s->group->name, $search) === false) {
                    return false;
                }

                return true;
            });
            if ($filtered->isEmpty()) {
                return true;
            }
            $filtered->load(['marks' => fn ($q) => $q->select('id', 'training_session_id', 'apprentice_id', 'code')]);
            $ids = $filtered->flatMap(fn ($s) => RegisterFollowup::roster($s)['ids'])->unique()->values();
            $unchecked = $ids->reject(fn ($id) => array_key_exists($id, $knownCache))->values();
            if ($unchecked->isNotEmpty()) {
                $found = array_flip(Apprentice::whereIn('id', $unchecked)->pluck('id')->all());
                foreach ($unchecked as $id) {
                    $knownCache[$id] = isset($found[$id]);
                }
            }
            $known = $ids->filter(fn ($id) => $knownCache[$id] ?? false)->values()->all();

            foreach ($filtered as $s) {
                $r = RegisterFollowup::readiness($s, $known);
                if (! $r['can_open']) {
                    continue;
                }
                if ($r['bucket'] === 'needs-register') {
                    $firstNeedsRegister = ['session' => $s, ...$r];

                    return false;
                }
                if ($firstTodayNotEnded === null && $r['bucket'] === 'not-ended' && $s->session_date->isToday()) {
                    $firstTodayNotEnded = ['session' => $s, ...$r];
                }
            }

            return true;
        });

        return $firstNeedsRegister ?? $firstTodayNotEnded;
    }

    public static function registerScope(mixed $input): array
    {
        abort_unless(is_array($input),422);
        return \Illuminate\Support\Facades\Validator::make($input,[
            'group'=>['nullable','integer','min:1'],'apprentice'=>['nullable','integer','min:1'],
            'search'=>['nullable','string','max:250'],
        ])->validate();
    }

    public static function apprentice(User $actor, Apprentice $a, ?array $plan): array
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
        $topics = TrainingTopic::where('apprentice_id', $a->id)
            ->get(['subject', 'level', 'topic', 'planned_hours', 'status']);

        return ['learning'=>PlanCurriculum::completion($tasks, $topics, $plan),
            'attendance'=>$attendance,'attendance_check'=>$attendanceCheck,
            'review_due'=>SignedReviews::cycle($a)['next_due']??null,'next'=>$work['rows']->first(),
            'outstanding'=>$work['rows']->count()];
    }
}
