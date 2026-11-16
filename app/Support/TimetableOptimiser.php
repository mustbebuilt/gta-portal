<?php
namespace App\Support;

use App\Models\{User,TimetablePlan as Plan,TimetablePlanEntry as Entry,TimetableOptimisationRun as Run,TimetableCoverAllocation,TimetableGovernanceState,TimetableGovernanceRole,TimetableAllocationControl};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class TimetableOptimiser
{
 public const MAX_PLANS=8;
 public const MAX_DAYS=93;
 public const MAX_FLEX_PLANS=4;
 public const MAX_DATE_FLEX=2;
 public const MAX_FLEX_OPTIONS=240;
 public const BEAM=32;
 public const KINDS=['Teaching','Marking','Preparation','Admin','Learner support','Staff development'];
 public static function range(string $from,string $to):array {
  try{$a=Carbon::createFromFormat('!Y-m-d',$from,config('app.timezone'));$b=Carbon::createFromFormat('!Y-m-d',$to,config('app.timezone'));}catch(\Throwable){CentrePlanning::fail('Choose a valid planning period.');}
  if($a->toDateString()!==$from||$b->toDateString()!==$to||$a->lt(today())||$b->lt($a)||$a->diffInDays($b)>=self::MAX_DAYS||$b->gt(today()->addYear())){CentrePlanning::fail('Choose up to93 current/future days within one year.');}
  $dates=[];for($d=$a->copy();$d->lte($b);$d->addDay()){$dates[]=$d->toDateString();}return $dates;
 }
 public static function eligible(User $actor,Plan $p,string $from,string $to,bool $lock=false):array {
  TimetableGovernance::guardPlan($actor,$p);
  if($p->state!=='Published'||!CentrePlanning::published($p)||!CentrePlanning::fresh($p,$lock)){CentrePlanning::fail('Use intact current Published plans; reconcile changed source first.');}
  $data=$p->source['data'];$args=$data['args'];$staff=User::findOrFail($args['staff_id']);
  if(!in_array($args['kind'],self::KINDS,true)||AccountLifecycle::blocked($staff)||!CentrePlanning::staff($staff)){CentrePlanning::fail('This activity or staff account cannot be optimised.');}
  foreach($data['rows']as$row){if($row['work_date']<$from||$row['work_date']>$to||!Carbon::parse($row['work_date'].' '.$row['start_time'],$data['timezone'])->gt(now())){CentrePlanning::fail('Every occurrence must be future and inside the selected period. Split or reconcile partly delivered series through the original workflow.');}}
  if($args['kind']==='Teaching'&&\App\Models\TrainingSession::where('tutor_group_id',$args['group_id'])->whereIn(DB::raw('DATE(session_date)'),array_column($data['rows'],'work_date'))->where('state','!=','Cancelled')->when($lock,fn($q)=>$q->lockForUpdate())->exists()){CentrePlanning::fail('A register already exists for this group on a selected date. Reconcile it through the original register workflow first.');}
  $entries=Entry::where('plan_id',$p->id)->orderBy('id')->when($lock,fn($q)=>$q->lockForUpdate())->get();
  if($entries->contains(fn($e)=>$e->training_session_id||$e->link_proof)||TimetableCoverAllocation::whereIn('entry_id',$entries->pluck('id'))->exists()){CentrePlanning::fail('Linked registers or cover history must be reconciled through their existing workflows; they cannot be moved here.');}
  return ['plan'=>$p,'data'=>$data,'entries'=>$entries->map->getAttributes()->all()];
 }
 private static function collision(array $rows,array $other):bool {foreach($rows as$a){foreach($other as$b){if(CentrePlanning::overlap($a,$b)&&CentrePlanning::shared($a,$b)){return true;}}}return false;}
 public static function gaps(array $rows,array $staffIds):int {
  $days=[];foreach($rows as$r){if(in_array($r['staff_id'],$staffIds,true)&&$r['kind']!=='Non-working'){$days[$r['staff_id'].':'.$r['work_date']][]=[CentrePlanning::minutes($r['start_time']),CentrePlanning::minutes($r['end_time'])];}}
  $sum=0;foreach($days as$intervals){$start=min(array_column($intervals,0));$end=max(array_column($intervals,1));$sum+=max(0,$end-$start-CentreTimetable::unionMinutes($intervals));}return $sum;
 }
 private static function score(array $selection,array $original,array $fixed,array $staff,bool $flex=false):array {
  $rows=$fixed;$moved=0;$shift=0;$dateDistance=0;foreach($original as$id=>$d){$candidate=$selection[$id]??$d;$rows=[...$rows,...$candidate['rows']];if($candidate['args']!==$d['args']){$moved++;$dateDistance+=(int)abs(Carbon::parse($candidate['args']['date'])->diffInDays(Carbon::parse($d['args']['date'])))*count($d['rows']);$shift+=abs(CentrePlanning::minutes($candidate['args']['start'])-CentrePlanning::minutes($d['args']['start']))*count($d['rows']);}}
  return $flex?[self::gaps($rows,$staff),$moved,$dateDistance,$shift]:[self::gaps($rows,$staff),$moved,$shift];
 }
 public static function preview(User $actor,array $ids,string $from,string $to,bool $lock=false,int $dateFlex=0):array {
  if($dateFlex<0||$dateFlex>self::MAX_DATE_FLEX||$dateFlex>0&&count($ids)>self::MAX_FLEX_PLANS){CentrePlanning::fail('Alternative dates support up to four plans and up to two days either side.');}
  $u=PortalActions::actor($actor);abort_unless(CentrePlanning::allowed($u),403);$dates=self::range($from,$to);
  if(!$ids||count($ids)>self::MAX_PLANS||count(array_unique($ids))!==count($ids)||array_filter($ids,fn($id)=>!is_string($id)||!Str::isUuid($id))){CentrePlanning::fail('Select1–8 different whole plans.');}sort($ids,SORT_STRING);
  $original=[];$bindings=[];$candidates=[];$staff=[];$searchIssues=[];$dateSources=[];
  foreach($ids as$id){$p=Plan::whereKey($id)->when($lock,fn($q)=>$q->lockForUpdate())->firstOrFail();$source=self::eligible($u,$p,$from,$to,$lock);$data=$source['data'];$original[$id]=$data;$staff[]=$data['args']['staff_id'];$bindings[$id]=['fields'=>$p->getAttributes(),'entries'=>$source['entries'],'control'=>TimetableAllocationControl::find($id)?->getAttributes()];
   $args=$data['args'];$duration=CentrePlanning::minutes($args['end'])-CentrePlanning::minutes($args['start']);$options=[];
   $radius=$args['kind']==='Teaching'?0:$dateFlex;
   for($day=-$radius;$day<=$radius;$day++){
    $first=Carbon::parse($args['date'])->addDays($day)->toDateString();
    try{$candidateDates=CentrePlanning::dates($first,(int)$args['weeks']);}catch(\Illuminate\Validation\ValidationException){continue;}
    if(min($candidateDates)<$from||max($candidateDates)>$to){continue;}
    $windows=CentrePlanning::windowSource(User::findOrFail($args['staff_id']),$candidateDates,$lock);$dateSources[$id][$first]=$windows;
    $start=0;$end=1439;$checked=count($windows['rows'])===count($candidateDates);
    foreach($windows['rows']as$w){if(!$w['valid']||!$w['fields']['working']){$checked=false;break;}$start=max($start,CentrePlanning::minutes($w['fields']['start']));$end=min($end,CentrePlanning::minutes($w['fields']['end']));}
    if(!$checked){$searchIssues[]=$id.': dates starting '.$first.' have unverified or non-working time and were withheld';continue;}
    $starts=[CentrePlanning::minutes($args['start'])];for($at=(int)(ceil($start/15)*15);$at+$duration<=$end;$at+=15){$starts[]=$at;}
    $starts=array_values(array_unique($starts));sort($starts,SORT_NUMERIC);
    foreach($starts as$at){$new=[...$args,'date'=>$first,'start'=>CentrePlanning::clock($at),'end'=>CentrePlanning::clock($at+$duration)];try{$projected=CentrePlanning::projection($new,$lock);$inspection=CentrePlanning::inspectProjection($projected,['rows'=>[],'uncertain'=>[]]);if(!$inspection['errors']){$options[]=$projected;}else{$searchIssues[]=$id.': an invalid working-time candidate was withheld';}}catch(\Illuminate\Validation\ValidationException){$searchIssues[]=$id.': an invalid local-time candidate was withheld';}}
   }
   if(count($options)>($dateFlex?self::MAX_FLEX_OPTIONS:50)){CentrePlanning::fail('Too many time/date choices; narrow the period or verified working windows before optimising.');}$candidates[$id]=$options;
  }
  $staff=array_values(array_unique($staff));$busy=CentrePlanning::busy($dates,null,$lock);
  if($busy['uncertain']||count($busy['rows'])>2000||collect($busy['rows'])->contains(fn($r)=>$r['stale']??false)){CentrePlanning::fail('The planning period has incomplete, changed or overlarge busy sources. Reconcile or narrow it first.');}
  $fixed=array_values(array_filter($busy['rows'],fn($r)=>!in_array($r['plan_id'],$ids,true)));
  // Deterministic bounded beam search. Every proposed full schedule receives canonical checks.
  $beam=[['chosen'=>[],'rows'=>[],'score'=>self::score([],$original,$fixed,$staff,$dateFlex>0)]];$evaluated=0;
  foreach($ids as$id){$next=[];foreach($beam as$state){foreach($candidates[$id]as$option){$evaluated++;if(self::collision($option['rows'],[...$fixed,...$state['rows']])){continue;}$chosen=$state['chosen']+[$id=>$option];$next[]=['chosen'=>$chosen,'rows'=>[...$state['rows'],...$option['rows']],'score'=>self::score($chosen,$original,$fixed,$staff,$dateFlex>0)];}}
   usort($next,fn($a,$b)=>$a['score']<=>$b['score']?:strcmp(ReviewedImports::hash($a['chosen']),ReviewedImports::hash($b['chosen'])));$beam=array_slice($next,0,self::BEAM);if(!$beam){break;}
  }
  // Keep the untouched complete schedule as a candidate even if beam pruning dropped it.
  $beam[]=['chosen'=>$original,'rows'=>array_merge(...array_map(fn($d)=>$d['rows'],array_values($original))),'score'=>self::score($original,$original,$fixed,$staff,$dateFlex>0)];
  usort($beam,fn($a,$b)=>$a['score']<=>$b['score']?:strcmp(ReviewedImports::hash($a['chosen']),ReviewedImports::hash($b['chosen'])));$best=null;$warnings=[];
  foreach($beam as$state){if(count($state['chosen'])!==count($ids)){continue;}$ok=true;$w=[];foreach($state['chosen']as$id=>$option){$others=$fixed;foreach($state['chosen']as$otherId=>$other){if($otherId!==$id){$others=[...$others,...$other['rows']];}}$inspection=CentrePlanning::inspectProjection($option,['rows'=>$others,'uncertain'=>[]]);if($inspection['errors']){$ok=false;break;}$w=[...$w,...$inspection['warnings']];}if($ok){$best=$state;$warnings=array_values(array_unique($w));break;}}
  $before=self::score($original,$original,$fixed,$staff,$dateFlex>0);$suggestion=[];
  if($best){foreach($ids as$id){$suggestion[$id]=['before'=>$original[$id]['args'],'after'=>$best['chosen'][$id]['args'],'staff_name'=>$original[$id]['staff']['name'],'group_name'=>$original[$id]['group']['name']??null,'room_name'=>$original[$id]['room']['fields']['name']??null,'dates'=>array_column($original[$id]['rows'],'work_date'),'proposed_dates'=>array_column($best['chosen'][$id]['rows'],'work_date'),'occurrences'=>count($original[$id]['rows']),'changed'=>$original[$id]['args']!==$best['chosen'][$id]['args']];}}
  $proof=['actor'=>ReviewAppointments::seal($u),'auth'=>$u->auth_version,'governance'=>TimetableGovernanceState::find(1)?->getAttributes(),'role'=>TimetableGovernanceRole::find($u->id)?->getAttributes(),'period'=>[$from,$to],'bindings'=>$bindings,'candidates'=>$candidates,'busy'=>$busy,'algorithm'=>$dateFlex?'nearby-nonteaching-dates-beam32-v51':'same-dates-beam32-v47'];
  if($dateFlex){$proof['date_flex']=$dateFlex;$proof['date_window_sources']=$dateSources;$proof['bounds']=[self::MAX_FLEX_PLANS,self::MAX_FLEX_OPTIONS];}
  return ['date_flex'=>$dateFlex,'ids'=>$ids,'from'=>$from,'to'=>$to,'source_hash'=>ReviewedImports::hash($proof),'suggestion'=>$suggestion,'before'=>$before,'after'=>$best? $best['score']:null,'warnings'=>$warnings,'search'=>['examined'=>$evaluated,'beam'=>self::BEAM,'options'=>array_map('count',$candidates),'issues'=>array_values(array_unique($searchIssues)),'global_optimum_proved'=>false],'can_apply'=>$best&&$best['score'][1]>0,'result'=>$best?($best['score'][1]>0?'Checked proposal available':'No checked improvement found'):'No checked complete schedule found'];
 }
 private static function fields(Run $r):array{return $r->only(['id','request_token','created_by','source_hash']);}
 public static function intact(Run $r):bool {
  try{if(!$r->details||ReviewedImports::hash($r->details)!==$r->details_hash||$r->details['fields']!==self::fields($r)){return false;}
   if($r->state==='Prepared'){return $r->version===1&&$r->receipt===null;}
   return in_array($r->state,['Applied','Cancelled'],true)&&$r->version===2&&$r->receipt&&$r->receipt['state']===$r->state&&$r->receipt['details_hash']===$r->details_hash&&ReviewedImports::hash($r->receipt)===$r->receipt_hash;
  }catch(\Throwable){return false;}
 }
 public static function prepare(User $actor,int $auth,array $ids,string $from,string $to,string $expected,string $token,string $reason,int $dateFlex=0):Run {
  if(!Str::isUuid($token)||mb_strlen(trim($reason))<5||mb_strlen($reason)>1000){CentrePlanning::fail('Use a fresh request and clear planning reason.');}
  return DB::transaction(function()use($actor,$auth,$ids,$from,$to,$expected,$token,$reason,$dateFlex){$u=PortalActions::actor(CentrePlanning::actor($actor,$auth));$sorted=$ids;sort($sorted,SORT_STRING);
   if($old=Run::where('request_token',$token)->lockForUpdate()->first()){if(self::intact($old)&&$old->created_by===$u->id&&$old->details['auth']===$auth&&$old->details['preview']['ids']===$sorted&&$old->details['preview']['from']===$from&&$old->details['preview']['to']===$to&&($old->details['preview']['date_flex']??0)===$dateFlex&&$old->source_hash===$expected&&$old->details['reason']===$reason){return $old;}CentrePlanning::fail('Request already belongs to different or damaged work.');}
   $preview=self::preview($u,$ids,$from,$to,true,$dateFlex);if(!hash_equals($preview['source_hash'],$expected)||!$preview['can_apply']){CentrePlanning::fail('The source changed or there is no checked improvement. Reopen the preview.');}
   $r=new Run(['id'=>(string)Str::uuid(),'request_token'=>$token,'created_by'=>$u->id,'source_hash'=>$expected,'state'=>'Prepared','version'=>1]);$r->details=['fields'=>self::fields($r),'auth'=>$auth,'reason'=>$reason,'preview'=>$preview,'expires'=>now()->addMinutes(30)->toIso8601String()];$r->details_hash=ReviewedImports::hash($r->details);$r->save();CentrePlanning::event($u,'optimisation_prepared',1,['run'=>$r->id,'source_hash'=>$expected,'no_reservations_changed'=>true]);return $r;
  },3);
 }
 public static function apply(User $actor,int $auth,Run $record,int $version,string $expected,bool $warningsAccepted):Run {
  return DB::transaction(function()use($actor,$auth,$record,$version,$expected,$warningsAccepted){$u=PortalActions::actor(CentrePlanning::actor($actor,$auth));$r=Run::whereKey($record->id)->lockForUpdate()->firstOrFail();abort_unless($r->created_by===$u->id,403);
   if(!self::intact($r)||$r->details['auth']!==$auth||$r->details_hash!==$expected||$version!==1){CentrePlanning::fail('The prepared request or current account changed.');}
   if($r->state==='Applied'){foreach($r->receipt['replacements']as$id){$p=Plan::findOrFail($id);TimetableGovernance::guardPlan($u,$p);if($p->state!=='Published'||!CentrePlanning::published($p)){CentrePlanning::fail('A previous replacement changed; inspect the recorded outcome.');}}return $r;}
   if($r->state!=='Prepared'||Carbon::parse($r->details['expires'])->lte(now())){CentrePlanning::fail('This proposal has expired or closed. Create a fresh preview.');}
   $saved=$r->details['preview'];$current=self::preview($u,$saved['ids'],$saved['from'],$saved['to'],true,(int)($saved['date_flex']??0));
   if($current['source_hash']!==$r->source_hash||$current['suggestion']!==$saved['suggestion']||!$current['can_apply']||$current['warnings']&&!$warningsAccepted){CentrePlanning::fail('Source, controls or warnings changed. Check a new proposal before applying.');}
   $changed=array_filter($saved['suggestion'],fn($s)=>$s['changed']);$new=[];
   // Withdraw all selected changed future reservations before publishing any replacement.
   // One outer transaction retains every original if any canonical check/audit fails.
   foreach($changed as$id=>$s){$old=Plan::whereKey($id)->lockForUpdate()->firstOrFail();CentrePlanning::withdraw($u,$auth,$old,$old->version,'Reviewed timetable optimisation: '.$r->details['reason']);}
   foreach($changed as$id=>$s){$p=CentrePlanning::save($u,$auth,null,0,(string)Str::uuid(),$s['after']);$new[$id]=$p->id;}
   foreach($new as$id=>$replacement){$p=Plan::findOrFail($replacement);CentrePlanning::check($u,$auth,$p,1,$warningsAccepted);CentrePlanning::publish($u,$auth,$p->refresh(),2);CentrePlanning::event($u,'optimisation_replacement',$p->refresh()->version,['run'=>$r->id,'old_plan'=>$id,'new_plan'=>$replacement,'dates_duration_staff_group_site_preserved'=>$saved['suggestion'][$id]['before']['date']===$saved['suggestion'][$id]['after']['date'],'duration_staff_group_room_site_cadence_preserved'=>true,'original_dates'=>$saved['suggestion'][$id]['dates'],'replacement_dates'=>$saved['suggestion'][$id]['proposed_dates']],$p);}
   foreach($saved['ids']as$id){$p=Plan::findOrFail($new[$id]??$id);$check=CentrePlanning::inspect($p,true);if($check['errors']){CentrePlanning::fail('Final combined timetable check failed; nothing was applied.');}}
   $r->state='Applied';$r->version=2;$r->receipt=['state'=>'Applied','details_hash'=>$r->details_hash,'by'=>$u->id,'auth'=>$auth,'at'=>now()->toIso8601String(),'replacements'=>$new,'attendance_or_otj_changed'=>false];$r->receipt_hash=ReviewedImports::hash($r->receipt);$r->save();CentrePlanning::event($u,'optimisation_applied',2,['run'=>$r->id,'receipt_hash'=>$r->receipt_hash,'replacements'=>$new]);return $r;
  },3);
 }
 public static function cancel(User $actor,int $auth,Run $record,int $version,string $expected):void {
  DB::transaction(function()use($actor,$auth,$record,$version,$expected){$u=PortalActions::actor(CentrePlanning::actor($actor,$auth));$r=Run::whereKey($record->id)->lockForUpdate()->firstOrFail();abort_unless($r->created_by===$u->id,403);if(!self::intact($r)||$r->details['auth']!==$auth||$r->state!=='Prepared'||$r->version!==$version||$r->details_hash!==$expected){CentrePlanning::fail('Proposal changed or already closed.');}$r->state='Cancelled';$r->version=2;$r->receipt=['state'=>'Cancelled','details_hash'=>$r->details_hash,'by'=>$u->id,'at'=>now()->toIso8601String()];$r->receipt_hash=ReviewedImports::hash($r->receipt);$r->save();CentrePlanning::event($u,'optimisation_cancelled',2,['run'=>$r->id,'no_reservations_changed'=>true]);},3);
 }
}
