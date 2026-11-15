<?php
namespace App\Support;
use App\Models\{User,TutorGroup,Apprentice,TrainingSession};
use Illuminate\Support\Facades\DB;
class RegisterCreation {
 public static function allowed(User $u,TutorGroup $g):bool {return $u->active&&(LearningAccess::manager($u)||$u->role==='tutor'&&$g->tutor_id===$u->id&&Apprentice::where('tutor_group_id',$g->id)->where(fn($q)=>$q->whereNull('tutor_id')->orWhere('tutor_id','!=',$u->id))->doesntExist());}
 public static function store(User $actor,int $auth,TutorGroup $group,array $data,array $breaks):TrainingSession {
 return DB::transaction(function()use($actor,$auth,$group,$data,$breaks):TrainingSession {
  $u=User::whereKey($actor->id)->lockForUpdate()->firstOrFail();$g=TutorGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();abort_unless($u->auth_version===$auth&&$g->active&&self::allowed($u,$g),403);
  $members=Apprentice::where('tutor_group_id',$g->id)->orderBy('id')->lockForUpdate()->get();if($members->isEmpty()){CentrePlanning::fail('Assign the actual register roster first.');}
  $v=validator($data,['name'=>'required|string|max:250','planned_learning'=>'required|string|max:10000','session_date'=>'required|date_format:Y-m-d','start_time'=>'required|date_format:H:i','end_time'=>'required|date_format:H:i|after:start_time'])->validate();
  if(!array_is_list($breaks)||count($breaks)>10){CentrePlanning::fail('Check the explicit excluded periods.');}foreach($breaks as$x){if(!is_array($x)||!TimetableChanges::time($x['start']??'')||!TimetableChanges::time($x['end']??'')||$x['start']<$v['start_time']||$x['end']>$v['end_time']||$x['end']<=$x['start']){CentrePlanning::fail('Excluded periods must be within the session.');}}
  if(TrainingSession::where('tutor_group_id',$g->id)->whereDate('session_date',$v['session_date'])->where('state','!=','Cancelled')->where('start_time','<',$v['end_time'])->where('end_time','>',$v['start_time'])->exists()){CentrePlanning::fail('An existing register overlaps this group. Link or reconcile it first.');}
  $s=TrainingSession::create($v+['tutor_group_id'=>$g->id,'created_by'=>$u->id,'members'=>$members->pluck('id')->all(),'breaks'=>$breaks]);
  foreach($s->members as$id){DB::table('learning_events')->insert(['user_id'=>$u->id,'apprentice_id'=>$id,'kind'=>'session','record_id'=>$s->id,'event'=>'session_planned','created_at'=>now()]);}
  return $s;
 },3);}
}
