<?php
namespace App\Support;
use Carbon\Carbon;
class SupportExposure {
 public static function summary($plans,?string $cutoff=null):array {
  $cases=collect();$unknown=0;
  foreach($plans as $p){$starts=collect($p->history??[])->filter(fn($h)=>($h['event']??null)==='approved')->map(fn($h)=>CareersFollowup::date(substr($h['at']??'',0,10)))->filter();$active=CareersFollowup::date(substr($p->active_plan['approved_at']??'',0,10));if($active){$starts->push($active);}$start=$starts->min();if($cutoff&&$start&&$start>$cutoff){continue;}
   if(!$start||($p->status==='Closed'&&!$p->closed_at)||!in_array($p->status,['Active','Closed'],true)){$unknown++;continue;}
   $cases->push((object)['acknowledged_at'=>Carbon::parse($start),'status'=>$p->status,'closed_at'=>$p->closed_at]);
  }
  $s=ConcernDuration::summarise($cases,$cutoff);return ['days'=>$s['days'],'intervals'=>$s['recorded_intervals'],'unknown'=>$unknown+$s['date_checks'],'as_of'=>$s['as_of']];
 }
}
