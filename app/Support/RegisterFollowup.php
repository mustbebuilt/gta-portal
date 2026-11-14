<?php
namespace App\Support;

use App\Models\{Apprentice,TrainingSession,TutorGroup,User};
use Illuminate\Support\Collection;

class RegisterFollowup
{
    public const VIEWS=['all','needs-register','recorded','not-ended','cancelled','checks'];
    public static function roster(TrainingSession $s): array
    {
        $raw=$s->members;$ids=[];$shape=is_array($raw) && array_is_list($raw);
        if($shape){foreach($raw as $id){if((!is_int($id) && !(is_string($id) && ctype_digit($id))) || (int)$id<1 || (is_string($id) && (string)(int)$id!==ltrim($id,'0'))){$shape=false;break;}$ids[]=(int)$id;}}
        return ['ids'=>$ids,'shape'=>$shape,'duplicates'=>count($ids)!==count(array_unique($ids))];
    }
    public static function readiness(TrainingSession $s,?array $knownIds=null): array
    {
        $roster=self::roster($s);$ids=array_values(array_unique($roster['ids']));$knownIds??=Apprentice::whereIn('id',$ids)->pluck('id')->all();$missingPeople=count(array_diff($ids,$knownIds));$marks=$s->marks;$by=$marks->keyBy('apprentice_id');$coded=0;$unknown=0;
        foreach($ids as $id){$code=$by->get($id)?->code;if($code!==null && $code!==''){if(array_key_exists($code,AttendanceMath::CODES)){$coded++;}else{$unknown++;}}}
        $outside=$marks->filter(fn($m)=>!in_array((int)$m->apprentice_id,$ids,true))->count();$duplicateMarks=$marks->count()!==$marks->pluck('apprentice_id')->unique()->count();
        $problems=!$roster['shape'] || $roster['duplicates'] || !$ids || $missingPeople || $unknown || $outside || $duplicateMarks;
        $ended=false;$timing=true;try{$ended=$s->ended();}catch(\Throwable){$problems=true;$timing=false;}
        $issues=[];foreach(['Malformed saved roster'=>!$roster['shape'],'Duplicate saved roster entries'=>$roster['duplicates'],'No saved roster entries'=>!$ids,'Roster records not found'=>$missingPeople>0,'Unrecognised attendance codes'=>$unknown>0,'Marks outside saved roster'=>$outside>0,'Duplicate mark records'=>$duplicateMarks,'Marks recorded before session end'=>!$ended && $marks->isNotEmpty(),'Unrecognised saved session state'=>!in_array($s->state,['Planned','Cancelled'],true),'Session timing needs checking'=>!$timing] as $label=>$problem){if($problem){$issues[]=$label;}}
        $bucket=$s->state==='Cancelled'?'cancelled':((!in_array($s->state,['Planned'],true) || $problems || (!$ended && $marks->isNotEmpty()))?'checks':(!$ended?'not-ended':($coded<count($ids)?'needs-register':'recorded')));
        return ['bucket'=>$bucket,'roster'=>count($ids),'coded'=>$coded,'missing_codes'=>max(0,count($ids)-$coded),'missing_people'=>$missingPeople,'unknown_codes'=>$unknown,'outside_marks'=>$outside,'roster_shape_ok'=>$roster['shape'],'duplicate_roster'=>$roster['duplicates'],'can_open'=>$roster['shape'] && $timing,'ended'=>$ended,'issues'=>$issues];
    }
    public static function visible(User $u): array
    {
        abort_unless($u->active && (LearningAccess::manager($u) || $u->role==='tutor'),403);
        $manager=LearningAccess::manager($u);$groups=TutorGroup::where('active',true)->when(!$manager,fn($q)=>$q->where('tutor_id',$u->id))->orderBy('name')->get();
        $sessions=TrainingSession::with(['group','marks'=>fn($q)=>$q->select('id','training_session_id','apprentice_id','code')])->whereIn('tutor_group_id',$groups->pluck('id'))->get();
        $sessions=$sessions->filter(function($s)use($u,$manager){if($manager){return true;}if(!self::roster($s)['shape']){return false;}return Apprentice::whereIn('id',$s->members)->where(fn($q)=>$q->whereNull('tutor_id')->orWhere('tutor_id','!=',$u->id))->doesntExist();});
        return ['groups'=>$groups,'sessions'=>$sessions];
    }
    public static function for(Collection $sessions): array
    {
        $ids=$sessions->flatMap(fn($s)=>self::roster($s)['ids'])->unique()->values();$known=Apprentice::whereIn('id',$ids)->pluck('id')->all();$rows=[];
        foreach($sessions as $s){$r=self::readiness($s,$known);$rows[]=['session'=>$s,...$r];}
        $rank=['needs-register'=>0,'checks'=>1,'not-ended'=>2,'recorded'=>3,'cancelled'=>4];usort($rows,fn($a,$b)=>[$rank[$a['bucket']],$a['session']->session_date->toDateString(),$a['session']->start_time,$a['session']->id]<=>[$rank[$b['bucket']],$b['session']->session_date->toDateString(),$b['session']->start_time,$b['session']->id]);$rows=collect($rows);$counts=['all'=>$rows->count()];foreach(self::VIEWS as $key){if($key!=='all'){$counts[$key]=$rows->where('bucket',$key)->count();}}
        return ['rows'=>$rows,'counts'=>$counts];
    }
}
