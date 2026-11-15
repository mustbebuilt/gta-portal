<?php
namespace App\Support;
use App\Models\{User,Apprentice,TrainingSession,LearningTask,SmartAction,ReviewAppointment};
use Carbon\Carbon;
class ApprenticeCalendar
{
 public static function capture(User $actor,string $from,string $to):array
 {
  $u=PortalActions::actor($actor);abort_unless($u->role==='apprentice',403);$a=ApprenticeExperience::profile($u);abort_unless($a,409,'Ask GTA to check your linked apprentice profile.');
  validator(compact('from','to'),['from'=>'required|date_format:Y-m-d','to'=>'required|date_format:Y-m-d|after_or_equal:from'])->validate();
  abort_unless($from>=today()->subYear()->toDateString()&&$to<=today()->addYear()->toDateString()&&Carbon::parse($from)->diffInDays(Carbon::parse($to))<=93,422,'Choose up to93 days within one year of today.');
  $events=[];$checks=0;$add=function($kind,$id,$version,$date,$title,$url,$start=null,$end=null,$cancelled=false)use(&$events,$a){$events[]=['uid'=>hash('sha256',$kind.':'.$id.':'.$a->id).'@gta-portal','kind'=>$kind,'source_id'=>$id,'version'=>(int)$version,'date'=>$date,'title'=>$title,'url'=>$url,'start'=>$start,'end'=>$end,'cancelled'=>$cancelled];};
  $sessions=TrainingSession::where(fn($q)=>$q->whereJsonContains('members',$a->id)->orWhereJsonContains('members',(string)$a->id))->whereDate('session_date','>=',$from)->whereDate('session_date','<=',$to)->limit(501)->get();abort_if($sessions->count()>500,422,'Narrow the calendar period.');
  foreach($sessions as$s){$roster=RegisterFollowup::roster($s);if(!$roster['shape']||$roster['duplicates']||!TimetableChanges::time($s->start_time)||!TimetableChanges::time($s->end_time)||$s->end_time<=$s->start_time){$checks++;continue;}$date=$s->session_date->toDateString();$add('Training session',$s->id,$s->version,$date,$s->name,route('attendance.apprentice',$a),Carbon::parse($date.' '.$s->start_time,config('app.timezone'))->utc()->format('Ymd\THis\Z'),Carbon::parse($date.' '.$s->end_time,config('app.timezone'))->utc()->format('Ymd\THis\Z'),$s->state==='Cancelled');}
  $tasks=LearningTask::where('apprentice_id',$a->id)->whereBetween('due_date',[$from,$to])->whereIn('status',['Draft','Referred'])->limit(501)->get();abort_if($tasks->count()>500,422,'Narrow the calendar period.');
  foreach($tasks as$t){if(($t->template['kind']??'source-pending')==='source-pending'){$checks++;continue;}$add('Task deadline',$t->id,$t->version,$t->due_date->toDateString(),$t->title,route('learning.index',$a));}
  $actions=SmartAction::with('task')->where('apprentice_id',$a->id)->where('owner','Apprentice')->limit(501)->get();abort_if($actions->count()>500,422,'Ask GTA to check the unusually large action source before exporting dates.');
  foreach($actions as$x){if($x->learning_task_id&&(!$x->task||$x->task->apprentice_id!==$a->id)){$checks++;continue;}$date=$x->deadline()?->toDateString();if($date&&$date>=$from&&$date<=$to&&$x->currentStatus()==='To do'){$add('Agreed action deadline',$x->id,$x->version,$date,$x->title,route('actions.index',$a).'#action-'.$x->id);}}
  $appointments=ReviewAppointment::where('apprentice_id',$a->id)->whereIn('state',['Published','Cancelled'])->whereDate('starts_at','>=',Carbon::parse($from,config('app.timezone'))->utc())->whereDate('starts_at','<=',Carbon::parse($to,config('app.timezone'))->endOfDay()->utc())->limit(501)->get();abort_if($appointments->count()>500,422,'Narrow the calendar period.');
  foreach($appointments as$p){if(!ReviewAppointments::visible($u,$p)){$checks++;continue;}$date=$p->starts_at->copy()->timezone(config('app.timezone'))->toDateString();if($date<$from||$date>$to){continue;}$add('Review appointment',$p->id,$p->version,$date,'Booked progress review',route('review-appointments.show',$p),$p->starts_at->copy()->utc()->format('Ymd\THis\Z'),$p->ends_at->copy()->utc()->format('Ymd\THis\Z'),$p->state==='Cancelled');}
  $cycle=SignedReviews::cycle($a);$due=$cycle['next_due'];if($due&&$due>=$from&&$due<=$to){$add('Review due reference','cycle-'.$due,1,$due,'Review due reference — confirm the appointment',route('electronic-reviews.index'));}
  abort_if(count($events)>500,422,'Narrow the calendar period to at most500 events.');usort($events,fn($x,$y)=>[$x['date'],$x['start']??'',$x['uid']]<=>[$y['date'],$y['start']??'',$y['uid']]);
  return ['reference'=>$a->reference,'from'=>$from,'to'=>$to,'captured_at'=>now()->toIso8601String(),'events'=>$events,'source_checks'=>$checks,'writes_performed'=>false];
 }
 private static function text(string $s):string{return str_replace(["\\","\r\n","\r","\n",';',','],["\\\\","\\n","\\n","\\n","\\;","\\,"],$s);}
 private static function fold(string $s):string{$parts=[];$limit=75;while(strlen($s)>$limit){$part=mb_strcut($s,0,$limit,'UTF-8');$parts[]=$part;$s=substr($s,strlen($part));$limit=74;}$parts[]=$s;return implode("\r\n ",$parts);}
 public static function ics(array $capture):string
 {
  $lines=['BEGIN:VCALENDAR','VERSION:2.0','PRODID:-//GTA//Portal calendar46//EN','CALSCALE:GREGORIAN','METHOD:PUBLISH','X-WR-CALNAME:GTA Portal dates'];
  foreach($capture['events']as$e){$lines[]='BEGIN:VEVENT';$lines[]='UID:'.$e['uid'];$lines[]='DTSTAMP:'.Carbon::parse($capture['captured_at'])->utc()->format('Ymd\THis\Z');$lines[]='SEQUENCE:'.$e['version'];$lines[]='SUMMARY:'.self::text($e['kind']);$lines[]='DESCRIPTION:'.self::text('Open the authenticated GTA Portal source for the current details. Calendar download is a copy, not a live subscription.');$lines[]='URL:'.self::text($e['url']);
   if($e['start']){$lines[]='DTSTART:'.$e['start'];$lines[]='DTEND:'.$e['end'];}else{$lines[]='DTSTART;VALUE=DATE:'.str_replace('-','',$e['date']);$lines[]='DTEND;VALUE=DATE:'.Carbon::parse($e['date'])->addDay()->format('Ymd');}
   $lines[]='STATUS:'.($e['cancelled']?'CANCELLED':'CONFIRMED');$lines[]='END:VEVENT';
  }$lines[]='END:VCALENDAR';return implode("\r\n",array_map([self::class,'fold'],$lines))."\r\n";
 }
}
