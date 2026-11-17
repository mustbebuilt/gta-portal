<?php
namespace App\Support;
use App\Models\{Apprentice,ProgrammeOutcome,DestinationMilestone as Task,DestinationMilestoneCheck as Check,User,EntryContextEvent as Event};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{DB,Validator};
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
class DestinationMilestones {
 public const DESTINATIONS=['Unknown','Same employer','New employer','Further learning','Seeking work','Other'];
 public const CONTINUITY=['Unknown','Continuous employment','Continuous training','Interrupted','Not applicable','Prefer not to say'];
 public static function sync(Apprentice $profile):void {
  DB::transaction(function()use($profile){
   $a=Apprentice::whereKey($profile->id)->lockForUpdate()->firstOrFail();$o=ProgrammeOutcome::where('apprentice_id',$a->id)->first();$anchor=LeaverDestinations::anchor($a,$o);$end=$anchor['end_date']??null;
   foreach(Task::where('apprentice_id',$a->id)->lockForUpdate()->get() as $t){if($t->exit_on->toDateString()!==$end&&$t->state!=='Superseded'){$t->state='Superseded';$t->version++;$t->save();}}
   if(!$end){return;}
   foreach([3,6,12] as $months){
    $t=Task::where('apprentice_id',$a->id)->whereDate('exit_on',$end)->where('months',$months)->first();
    $t??=Task::create(['apprentice_id'=>$a->id,'exit_on'=>$end,'months'=>$months,'due_on'=>CarbonImmutable::parse($end)->addMonthsNoOverflow($months)->toDateString(),'owner_id'=>null]);
    if($t->state==='Superseded'){$t->state='Open';$t->retry_on=null;$t->version++;$t->save();}
   }
  });
 }
 public static function current(Task $t):bool {
  $a=Apprentice::find($t->apprentice_id);$o=$a?ProgrammeOutcome::where('apprentice_id',$a->id)->first():null;$end=$a?(LeaverDestinations::anchor($a,$o)['end_date']??null):null;
  return $t->state!=='Superseded'&&$t->exit_on->toDateString()===$end;
 }
 public static function assign(User $u,Task $task,int $version,int $owner):void {
  abort_unless(LearningAccess::manager($u),403);
  DB::transaction(function()use($u,$task,$version,$owner){$t=Task::whereKey($task->id)->lockForUpdate()->firstOrFail();
   if($t->version!==$version||!self::current($t)){throw ValidationException::withMessages(['version'=>'Follow-up source changed. Reload first.']);}
   $who=User::findOrFail($owner);if(!LearningAccess::manager($who)){throw ValidationException::withMessages(['owner_id'=>'Choose an active administrator or CEO.']);}
   $before=$t->owner_id;$t->owner_id=$owner;$t->version++;$t->save();
   Event::create(['apprentice_id'=>$t->apprentice_id,'actor_id'=>$u->id,'kind'=>'Followup owner','revision'=>$t->version,'payload'=>['task'=>$t->id,'before'=>$before,'after'=>$owner]]);
  });
 }
 public static function record(User $u,Task $task,array $input):Check {
  abort_unless(LearningAccess::manager($u),403);
  $d=Validator::make($input,[
   'version'=>['required','integer','min:1'],'observed_on'=>['required','date_format:Y-m-d','before_or_equal:today'],
   'result'=>['required','in:Reached,No reply,Declined'],'destination'=>['required',Rule::in(self::DESTINATIONS)],'basis'=>['required','in:Unknown,Reported,Verified'],
   'continuity'=>['required',Rule::in(self::CONTINUITY)],'continuous_from'=>['nullable','date_format:Y-m-d'],
   'retry_on'=>['nullable','date_format:Y-m-d','after_or_equal:today'],'source'=>['required','string','max:1000']
  ])->validate();
  return DB::transaction(function()use($u,$task,$d){
   Apprentice::whereKey($task->apprentice_id)->lockForUpdate()->firstOrFail();$t=Task::whereKey($task->id)->lockForUpdate()->firstOrFail();
   if($t->version!==(int)$d['version']||!self::current($t)){throw ValidationException::withMessages(['version'=>'Follow-up changed or the exit source is no longer current. Reload first.']);}
   if($d['observed_on']<$t->due_on->toDateString()){throw ValidationException::withMessages(['observed_on'=>'A milestone check must be on or after its due date. Use ordinary contact history for earlier attempts.']);}
   if($d['result']==='No reply'&&empty($d['retry_on'])){throw ValidationException::withMessages(['retry_on'=>'Choose a retry date for no reply.']);}
   if($d['result']!=='Reached'&&($d['destination']!=='Unknown'||$d['basis']!=='Unknown'||$d['continuity']!=='Unknown'||filled($d['continuous_from']??null))){throw ValidationException::withMessages(['destination'=>'No reply or declined cannot establish a destination or continuity.']);}
   if(($d['destination']==='Unknown')!==($d['basis']==='Unknown')){throw ValidationException::withMessages(['basis'=>'Known destinations need Reported or Verified; Unknown destinations keep Unknown evidence.']);}
   $continuous=in_array($d['continuity'],['Continuous employment','Continuous training'],true);
   if($continuous&&(empty($d['continuous_from'])||$d['continuous_from']>$d['observed_on']||($d['continuity']==='Continuous employment'&&!in_array($d['destination'],['Same employer','New employer'],true))||($d['continuity']==='Continuous training'&&$d['destination']!=='Further learning'))){throw ValidationException::withMessages(['continuous_from'=>'Confirm the uninterrupted period start and matching employment/training destination.']);}
   if(!$continuous&&filled($d['continuous_from']??null)){throw ValidationException::withMessages(['continuous_from'=>'A continuity start is only recorded for confirmed continuous employment/training.']);}
   $latest=Check::where('milestone_id',$t->id)->max('observed_on');
   if($latest&&$d['observed_on']<substr($latest,0,10)){throw ValidationException::withMessages(['observed_on'=>'This check is older than the latest saved check. Use the ordinary contact history for historical contacts.']);}
   $c=Check::create(array_merge(collect($d)->except('version')->all(),['milestone_id'=>$t->id,'actor_id'=>$u->id]));
   $t->state=$d['result']==='No reply'?'Open':'Checked';$t->retry_on=$d['retry_on']??null;$t->version++;$t->save();return $c;
  });
 }
 public static function summary($tasks,$checks):array {
  $result=[];foreach([3,6,12] as $months){$selected=$tasks->where('months',$months)->where('state','!=','Superseded');$due=$selected->filter(fn($t)=>$t->due_on->toDateString()<=today()->toDateString());$r=['eligible'=>$selected->count(),'due'=>$due->count(),'checked'=>0,'known'=>0,'uninterrupted_from_exit'=>0,'verified_uninterrupted'=>0,'unknown'=>0];
   foreach($due as $t){$c=$checks->get($t->id);if($c){$r['checked']++;if($c->destination!=='Unknown'){$r['known']++;}if(in_array($c->continuity,['Continuous employment','Continuous training'],true)&&$c->continuous_from&&$c->continuous_from->toDateString()<=$t->exit_on->toDateString()){$r['uninterrupted_from_exit']++;if($c->basis==='Verified'){$r['verified_uninterrupted']++;}}}if(!$c||$c->destination==='Unknown'){$r['unknown']++;}}
   $result[$months]=$r;
  }return $result;
 }
}
