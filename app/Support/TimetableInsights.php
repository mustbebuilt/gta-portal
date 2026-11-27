<?php

namespace App\Support;

use App\Models\{User, Apprentice, LearningTask, TutorGroup, TrainingSession, TimetableDeliveryRecord as Delivery};
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TimetableInsights
{
    /** A digest of actual register evidence, independent of today's group tutor. */
    public static function source(TrainingSession $session): array
    {
        $s = TrainingSession::with('marks')->findOrFail($session->id);
        $ready = RegisterFollowup::readiness($s);
        return ['session'=>$s, 'readiness'=>$ready, 'hash'=>ReviewedImports::hash([
            'session'=>$s->getAttributes(), 'group_programme'=>$s->group?->programme_id, 'timezone'=>config('app.timezone'),
            'marks'=>$s->marks->sortBy('id')->map(fn($m)=>$m->getAttributes())->values()->all(),
        ])];
    }

    public static function record(Delivery $r, array $source): ?array
    {
        try {
            $d = $r->details;
            return $d['session_id'] === $r->training_session_id && $d['staff_id'] === $r->staff_id
                && $d['version'] === $r->version && $d['register_hash'] === $source['hash']
                && ReviewedImports::hash($d) === $r->details_hash
                && $source['readiness']['bucket'] === 'recorded' ? $d : null;
        } catch (\Throwable) { return null; }
    }

    /** One explicit delivery confirmation; it never fills or changes a register. */
    public static function confirm(User $actor, int $auth, TrainingSession $session, User $teacher,
        int $version, string $hash, bool $cover, string $reason): void
    {
        DB::transaction(function () use ($actor,$auth,$session,$teacher,$version,$hash,$cover,$reason): void {
            User::whereIn('id',[$actor->id,$teacher->id])->orderBy('id')->lockForUpdate()->get();
            $u = CentrePlanning::actor($actor,$auth);
            abort_unless(TimetableGovernance::manager($u),403);
            $t = User::findOrFail($teacher->id);
            abort_unless(in_array($t->role,['tutor','functional_skills_tutor'],true),422);
            $s = TrainingSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            // Canonical marking locks the session; also lock current marks before hashing.
            $s->marks()->orderBy('id')->lockForUpdate()->get();
            TutorGroup::whereKey($s->tutor_group_id)->lockForUpdate()->first();
            $source = self::source($s);
            $hasAttendance = $source['session']->marks->contains(fn($m)=>in_array($m->code,['O','T'],true));
            if ($source['hash'] !== $hash || $source['readiness']['bucket'] !== 'recorded'
                || !$hasAttendance || !filled($s->delivered_learning) || !TimetableChanges::time($s->start_time)
                || !TimetableChanges::time($s->end_time) || $s->end_time <= $s->start_time
                || trim($reason)==='' || mb_strlen($reason)>1000) {
                CentrePlanning::fail('Use a current ended, fully recorded register with delivery details and attended learning. Confirm the actual tutor and a safe source reference.');
            }
            $parts = self::parts($s);
            if (!$parts) { CentrePlanning::fail('Check the session times and excluded breaks.'); }
            $r = Delivery::whereKey($s->id)->lockForUpdate()->first();
            if (($r?->version ?? 0) !== $version) { CentrePlanning::fail(); }
            $before = $r?->details;
            $r ??= new Delivery(['training_session_id'=>$s->id]);
            $r->staff_id = $t->id;
            $r->version = $version + 1;
            $r->details = ['session_id'=>$s->id,'staff_id'=>$t->id,'staff_name'=>$t->name,
                'version'=>$r->version,'register_hash'=>$hash,'group_id'=>$s->tutor_group_id,
                'programme_id'=>$s->group?->programme_id,'cover'=>$cover,
                'date'=>$s->session_date->toDateString(),'parts'=>$parts,'timing_basis'=>'Union of recorded attended intervals, excluding recorded breaks',
                'by'=>$u->id,'at'=>now()->toIso8601String(),'reason'=>trim($reason)];
            $r->details_hash = ReviewedImports::hash($r->details);
            $r->save();
            TimetableDuties::event($u,'delivery_confirmed','session',$s->id,$r->version,
                ['before'=>$before,'after'=>$r->details,'register_unchanged'=>true]);
        },3);
    }

    public static function parts(TrainingSession $s): array
    {
        if (!TimetableChanges::time($s->start_time) || !TimetableChanges::time($s->end_time)
            || $s->end_time <= $s->start_time || !is_array($s->breaks)) { return []; }
        foreach ($s->breaks ?? [] as $b) {
            if (!is_array($b) || !TimetableChanges::time($b['start']??'') || !TimetableChanges::time($b['end']??'')
                || $b['start'] < $s->start_time || $b['end'] > $s->end_time || $b['end'] <= $b['start']) { return []; }
        }
        $intervals=[];
        foreach($s->marks as $mark){
            if(!in_array($mark->code,['O','T'],true)){continue;}
            if(!TimetableChanges::time($mark->arrival??'')||!TimetableChanges::time($mark->departure??'')||$mark->departure<=$mark->arrival){return [];}
            $parts=AttendanceMath::parts($mark->arrival,$mark->departure,$s->start_time,$s->end_time,$s->breaks);
            if(!$parts){return [];}$intervals=[...$intervals,...$parts];
        }
        usort($intervals,fn($a,$b)=>$a[0]<=>$b[0]);$union=[];
        foreach($intervals as[$a,$b]){$last=count($union)-1;if($last>=0&&$a<=$union[$last][1]){$union[$last][1]=max($union[$last][1],$b);}else{$union[]=[$a,$b];}}
        return $union;
    }

    public static function report(User $u,string $from,string $to,?int $programme=null): array
    {
        abort_unless(TimetableGovernance::manager($u),403);
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/D',$from)&&preg_match('/^\d{4}-\d{2}-\d{2}$/D',$to),422);
        abort_unless(checkdate((int)substr($from,5,2),(int)substr($from,8,2),(int)substr($from,0,4))&&checkdate((int)substr($to,5,2),(int)substr($to,8,2),(int)substr($to,0,4)),422);
        $a=Carbon::createFromFormat('!Y-m-d',$from,config('app.timezone'));
        $b=Carbon::createFromFormat('!Y-m-d',$to,config('app.timezone'));
        abort_unless($a->toDateString()===$from && $b->toDateString()===$to && $b->gte($a)
            && $a->diffInDays($b)<=92,422,'Choose a reporting interval of at most 93 days.');
        $dates=[];for($d=$a->copy();$d->lte($b);$d->addDay()){$dates[]=$d->toDateString();}
        $busy=CentrePlanning::busy($dates);
        $groups=TutorGroup::get()->keyBy('id');
        $rows=[];$stalePlans=0;
        $ensure=function(int $id)use(&$rows):void {
            if(isset($rows[$id])){return;}
            $name=User::find($id)?->name??'Former staff';
            $rows[$id]=['id'=>$id,'name'=>$name,'planned_dates'=>[],'planned_sessions'=>0,
                'delivery_dates'=>[],'delivery_sessions'=>0,'cover_dates'=>[], 'parts'=>[],
                'apprentices'=>[],'attendance_rows'=>[],'roster_seats'=>0,'sources'=>[],
                'current_tasks'=>['assigned'=>0,'approved'=>0,'awaiting'=>0,'overdue_marking'=>0,'overdue_tasks'=>0],
                'current_caseload'=>0,'programmes'=>[]];
        };
        foreach(User::whereIn('role',['tutor','functional_skills_tutor'])->where('active',true)->get()as$t){$ensure($t->id);}
        // Only actual published plan rows belong in the planned column. An unlinked
        // register may point to today's owner; it is not historical teaching attribution.
        foreach($busy['rows']as$r){
            if($r['kind']==='Teaching'&&$r['plan_id']&&$r['stale']){$stalePlans++;}
            if($r['kind']!=='Teaching'||!$r['plan_id']||$r['stale']
                ||($programme&&($groups->get($r['group_id'])?->programme_id!==$programme))){continue;}
            $ensure($r['staff_id']);$x=&$rows[$r['staff_id']];
            $x['planned_dates'][$r['work_date']]=true;$x['planned_sessions']++;
            $label=$groups->get($r['group_id'])?->programme?->name??'Programme unknown';$x['programmes'][$label]=true;
            unset($x);
        }
        $sessions=TrainingSession::with(['marks','group'])->whereDate('session_date','>=',$from)
            ->whereDate('session_date','<=',$to)->orderBy('session_date')->orderBy('id')->limit(1001)->get();
        abort_if($sessions->count()>1000,422,'Narrow the interval; more than 1,000 register sessions.');
        $queue=[];$counts=['recorded'=>0,'needs-register'=>0,'not-ended'=>0,'cancelled'=>0,'checks'=>0,
            'unattributed'=>0,'confirmed'=>0];
        foreach($sessions as$s){
            $record=Delivery::find($s->id);$source=self::source($s);$s=$source['session'];$confirmed=$record?self::record($record,$source):null;
            $programmeId=$confirmed?$confirmed['programme_id']:$s->group?->programme_id;
            if($programme&&$programmeId!==$programme){continue;}
            $ready=$source['readiness'];$counts[$ready['bucket']]++;
            if(!$confirmed&&$ready['ended']&&$ready['bucket']!=='cancelled'){$counts['unattributed']++;}
            if($confirmed){
                $counts['confirmed']++;$id=$confirmed['staff_id'];$ensure($id);$x=&$rows[$id];
                $date=$s->session_date->toDateString();$x['delivery_dates'][$date]=true;
                if($confirmed['cover']){$x['cover_dates'][$date]=true;}
                $x['delivery_sessions']++;$x['parts'][$date]=[...($x['parts'][$date]??[]),...$confirmed['parts']];
                $ids=RegisterFollowup::roster($s)['ids'];$x['roster_seats']+=count($ids);
                foreach($ids as$id){$x['apprentices'][$id]=true;$mark=$s->marks->firstWhere('apprentice_id',$id);
                    $x['attendance_rows'][]=['cancelled'=>false,'code'=>$mark?->code,'minutes'=>null,'date'=>$date];}
                $label=\App\Models\Programme::find($programmeId)?->name??'Programme unknown';$x['programmes'][$label]=true;
                $x['sources'][]=$s->id;unset($x);
            }
            $queue[]=['id'=>$s->id,'date'=>$s->session_date->toDateString(),'group'=>$s->group?->name??'Group unknown',
                'state'=>$ready['bucket'],'confirmed'=>$confirmed,'source_hash'=>$source['hash'],
                'record_version'=>$record?->version??0,'can_confirm'=>$ready['bucket']==='recorded'
                    &&filled($s->delivered_learning)&&(bool)self::parts($s)&&$s->marks->contains(fn($m)=>in_array($m->code,['O','T'],true))];
        }
        foreach($rows as$id=>&$x){
            $people=Apprentice::where('tutor_id',$id)->when($programme,fn($q)=>$q->where('programme_id',$programme))->get();
            $x['current_caseload']=$people->count();
            $tasks=LearningTask::whereIn('apprentice_id',$people->pluck('id'))->get(['id','apprentice_id','status','submitted_at','due_date']);
            $x['current_tasks']=['assigned'=>$tasks->count(),'approved'=>$tasks->where('status','Approved')->count(),
                'awaiting'=>$tasks->where('status','Awaiting marking')->count(),
                'overdue_marking'=>$tasks->filter(fn($t)=>$t->markingOverdue())->count(),
                'overdue_tasks'=>$tasks->filter(fn($t)=>$t->status!=='Approved'&&$t->due_date&&$t->due_date->lt(today()))->count()];
            $x['attendance']=AttendanceMath::report($x['attendance_rows']);
            $x['delivered_minutes']=array_sum(array_map(fn($parts)=>CentreTimetable::unionMinutes($parts),$x['parts']));
            $x['average_roster']=$x['delivery_sessions']?$x['roster_seats']/$x['delivery_sessions']:null;
            // Expose aggregate counts only, never private apprentice identities to the view.
            $x['unique_apprentices']=count($x['apprentices']);unset($x['apprentices'],$x['attendance_rows'],$x['parts']);
        }unset($x);
        usort($rows,fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));
        return ['from'=>$from,'to'=>$to,'programme'=>$programme,'rows'=>$rows,'registers'=>$queue,
            'counts'=>$counts,'stale_plans'=>$stalePlans,'source_warnings'=>$busy['uncertain'],'captured_at'=>now()->toIso8601String()];
    }
}
