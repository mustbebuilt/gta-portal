<?php
namespace App\Support;
use App\Models\{User,TimetablePlan as Plan,TimetablePlanEntry as Entry,TimetableStaffWindow as Window};
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;
use Carbon\Carbon;
class TimetableWorkMoves
{
 public const KINDS=['Marking','Preparation','Admin','Learner support','Staff development'];
 private static function source(Plan $p):array
 {
  if($p->state!=='Published'||!CentrePlanning::published($p)||!CentrePlanning::fresh($p)||count($p->source['data']['rows'])!==1){CentrePlanning::fail('Use one intact current future non-teaching activity; series need their original planning workflow.');}
  $args=$p->source['data']['args'];$entry=Entry::where('plan_id',$p->id)->first();
  if(!in_array($args['kind'],self::KINDS,true)||!Carbon::parse($args['date'].' '.$args['start'])->gt(now())||!$entry||$entry->training_session_id||$entry->link_proof){CentrePlanning::fail('Only future non-teaching work without a register link can move here.');}
  return $args;
 }
 public static function preview(User $actor,Plan $record,bool $lock=false):array
 {
  $u=PortalActions::actor($actor);abort_unless(CentrePlanning::allowed($u),403);$p=Plan::whereKey($record->id)->when($lock,fn($q)=>$q->lockForUpdate())->firstOrFail();TimetableGovernance::guardPlan($u,$p);$args=self::source($p);$staff=User::findOrFail($args['staff_id']);abort_unless(CentrePlanning::staff($staff)&&!AccountLifecycle::blocked($staff),403);
  $dates=[];for($i=0;$i<14;$i++){$dates[]=today()->addDays($i)->toDateString();}
  $windows=CentrePlanning::windowSource($staff,$dates,$lock);$busy=CentrePlanning::busy($dates,$p->id,$lock);$duration=CentrePlanning::minutes($args['end'])-CentrePlanning::minutes($args['start']);$options=[];$unknown=[];
  if(!$busy['uncertain']){foreach($dates as$date){
   $w=Window::where('user_id',$staff->id)->whereDate('work_date',$date)->when($lock,fn($q)=>$q->lockForUpdate())->first();
   if(!$w||!CentrePlanning::windowIntact($w)){$unknown[]=$date;continue;}if(!$w->working){continue;}
   $start=CentrePlanning::minutes($w->start_time);$end=CentrePlanning::minutes($w->end_time);
   // Quarter-hour candidates, same duration and site. Final canonical checks remain mandatory.
   for($at=(int)(ceil($start/15)*15);$at+$duration<=$end;$at+=15){
    $time=CentrePlanning::clock($at);$finish=CentrePlanning::clock($at+$duration);if(!Carbon::parse($date.' '.$time)->gt(now())||$date===$args['date']&&$time===$args['start']){continue;}
    $candidate=['work_date'=>$date,'start_time'=>$time,'end_time'=>$finish,'staff_id'=>$args['staff_id'],'room_id'=>$args['room_id'],'group_id'=>null,'members'=>[]];$clash=false;
    foreach($busy['rows']as$row){if(CentrePlanning::overlap($candidate,$row)&&CentrePlanning::shared($candidate,$row)){$clash=true;break;}}
    if(!$clash){$options[]=['choice'=>$date.' '.$time,'date'=>$date,'start'=>$time,'end'=>$finish];}
    if(count($options)>=60){break 2;}
   }
  }}
  $proof=['plan'=>$p->getAttributes(),'source_hash'=>$p->source_hash,'entry'=>Entry::where('plan_id',$p->id)->first()?->getAttributes(),'windows'=>$windows,'busy'=>$busy,'options'=>$options];
  return ['plan'=>$p,'args'=>$args,'duration'=>$duration,'options'=>$options,'unknown_dates'=>$unknown,'uncertain_count'=>count($busy['uncertain']),'hash'=>ReviewedImports::hash($proof),'search_days'=>14,'max_options'=>60];
 }
 public static function apply(User $actor,int $auth,Plan $record,int $version,string $expected,string $choice,string $token,string $reason,bool $warningsAccepted):Plan
 {
  if(!Str::isUuid($token)||mb_strlen(trim($reason))<5||mb_strlen($reason)>1000){CentrePlanning::fail('Use a fresh request and a clear move reference.');}
  return DB::transaction(function()use($actor,$auth,$record,$version,$expected,$choice,$token,$reason,$warningsAccepted){
   $u=CentrePlanning::actor($actor,$auth);$old=Plan::whereKey($record->id)->lockForUpdate()->firstOrFail();TimetableGovernance::guardPlan($u,$old);
   if($prior=Plan::where('request_token',$token)->lockForUpdate()->first()){
    TimetableGovernance::guardPlan($u,$prior);$event=DB::table('timetable_planning_events')->where('plan_id',$prior->id)->where('event','work_rescheduled')->first();
    try{$proof=$event?json_decode(Crypt::decryptString($event->payload),true,512,JSON_THROW_ON_ERROR):null;}catch(\Throwable){$proof=null;}
    $want=['old_plan'=>$old->id,'old_version'=>$version,'preview_hash'=>$expected,'choice'=>$choice,'request'=>$token,'reason'=>$reason,'actor'=>$u->id,'auth'=>$u->auth_version];
    if(!$proof||($proof['request_binding']??null)!==$want||!CentrePlanning::published($prior)||$prior->created_by!==$u->id){CentrePlanning::fail('This request already belongs to another or changed move.');}return $prior;
   }
   if($old->version!==$version){CentrePlanning::fail('The original plan changed. Reopen the move preview.');}
   $preview=self::preview($u,$old,true);if($preview['hash']!==$expected){CentrePlanning::fail('Working time, timetable or source changed. Check a new preview.');}
   $selected=collect($preview['options'])->firstWhere('choice',$choice);if(!$selected){CentrePlanning::fail('Choose an available option from this exact preview.');}
   $args=$preview['args'];$newArgs=[...$args,'date'=>$selected['date'],'start'=>$selected['start'],'end'=>$selected['end'],'weeks'=>1];
   // One transaction: any canonical validation or audit failure restores the original reservation.
   CentrePlanning::withdraw($u,$auth,$old,$version,'Rescheduled work: '.$reason);
   $new=CentrePlanning::save($u,$auth,null,0,$token,$newArgs);CentrePlanning::check($u,$auth,$new,1,$warningsAccepted);CentrePlanning::publish($u,$auth,$new->refresh(),2);
   CentrePlanning::event($u,'work_rescheduled',$new->refresh()->version,['request_binding'=>['old_plan'=>$old->id,'old_version'=>$version,'preview_hash'=>$expected,'choice'=>$choice,'request'=>$token,'reason'=>$reason,'actor'=>$u->id,'auth'=>$u->auth_version],'replacement'=>$new->id,'preserved_minutes'=>$preview['duration'],'actual_attendance_or_task_completion_changed'=>false],$new);
   return $new;
  },3);
 }
}
