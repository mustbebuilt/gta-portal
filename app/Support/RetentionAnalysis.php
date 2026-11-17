<?php
namespace App\Support;
class RetentionAnalysis {
 public static function exitDestination(array $context):string {
  $end=CareersFollowup::date($context['endDate']??null);
  if(!$end||($context['destinationDate']??null)!==$end||!in_array($context['destinationBasis']??null,['Reported','Verified'],true)||!filled($context['destinationRef']??null)){return 'Unknown at exit';}
  return $context['destination']??'Unknown at exit';
 }
 public static function rate(int $n,int $d):?float {return $d?round(100*$n/$d,1):null;}
 public static function groups(array $rows):\Illuminate\Support\Collection {
  return collect($rows)->groupBy('factor')->map(function($g){
   $completed=$g->where('status','Completed')->count();$withdrawn=$g->where('status','Withdrawn')->count();
   $eligible=$completed+$withdrawn;$known=$g->where('status','Completed')->whereIn('achievement',['Achieved','Not achieved']);$achieved=$known->where('achievement','Achieved')->count();
   $durations=$g->where('status','Withdrawn')->pluck('days_before_leaving')->filter(fn($v)=>$v!==null)->sort()->values();$n=$durations->count();
   $median=$n?($durations[(int)floor(($n-1)/2)]+$durations[(int)floor($n/2)])/2:null;
   $att=$g->map(fn($r)=>$r['recorded_attendance']['percent']??null)->filter(fn($v)=>$v!==null);
   $taskRows=$g->filter(fn($r)=>($r['assigned_tasks']??0)>0);
   $supportDays=$g->map(fn($r)=>$r['support_exposure']['days']??null)->filter(fn($v)=>$v!==null);
   $reasons=$g->where('status','Withdrawn')->groupBy('reason')->map->count()->all();
   $reasonConcerns=$g->where('status','Withdrawn')->groupBy(fn($r)=>($r['reason']??'Not established').' / '.$r['concern_codes'])->map->count()->all();
   return ['attendance_known'=>$att->count(),'attendance_mean'=>$att->count()?round($att->avg(),1):null,'task_records'=>$taskRows->count(),'tasks_assigned'=>$taskRows->sum('assigned_tasks'),'tasks_approved'=>$taskRows->sum('approved_tasks'),'support_delivered_people'=>$g->filter(fn($r)=>($r['support_activities']??0)>0)->count(),'support_days_known'=>$supportDays->count(),'support_days_mean'=>$supportDays->count()?round($supportDays->avg(),1):null,'reasons'=>$reasons,'reason_concerns'=>$reasonConcerns,'total' =>$g->count(),'completed'=>$completed,'withdrawn'=>$withdrawn,'achieved'=>$achieved,'unknown'=>$g->where('status','Unknown')->count(),'terminal'=>$eligible,'known_results'=>$known->count(),'transferred'=>$g->where('status','Transferred')->count(),'withdrawal_rate'=>self::rate($withdrawn,$eligible),'achievement_rate'=>self::rate($achieved,$known->count()),'missing_results'=>$completed-$known->count(),'median_leaving_days'=>$median,'leaving_dates_known'=>$n,'small'=>$g->count()<10,'concern_combinations'=>$g->groupBy('concern_codes')->map->count()->all(),'exit_destinations'=>$g->groupBy('exit_destination')->map->count()->all()];
  });
 }
}
