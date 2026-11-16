<?php
namespace App\Support;
use App\Models\Apprentice;
/** Presentation only; never creates milestones or derives a completion percentage. */
class JourneyMarker {
 public static function for(Apprentice $a,array $events):array {
  $programme=$a->programmeRecord;$code=$programme&&$programme->active?strtoupper(trim($programme->standard_code)):null;
  $vehicle=match($code){'ST0068'=>'lorry','ST0033','ST0499','ST0403','ST0448'=>'car',default=>'neutral'};
  $index=null;$date=null;$previous=null;$ties=0;
  foreach($events as$i=>$event){$value=$event['date']??null;if(!is_string($value)||!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$value,$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1])||$value>today()->toDateString())continue;
   if($date===null||$value>=$date){$previous=$index;$ties=$date===$value?$ties+1:1;$date=$value;$index=$i;}
  }
  return['vehicle'=>$vehicle,'index'=>$index,'previous_index'=>$previous,'date'=>$date,'same_date_records'=>$ties,'label'=>match($vehicle){'car'=>'GTA car','lorry'=>'GTA lorry',default=>'Journey marker'}];
 }
}
