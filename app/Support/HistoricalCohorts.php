<?php
namespace App\Support;

use App\Models\{User,Apprentice,Programme,ProgrammeOutcome,HistoricalCohort as Cohort,HistoricalCohortRow as Row};
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HistoricalCohorts
{
 public const BASES=['Documented tutor at recorded planned end','Shared or changing responsibility','Unable to establish responsibility'];
 public const MIN_SAMPLE=5;
 public const MAX_ROWS=500;
 public static function label(Cohort $c):string {if(!self::intact($c)){return 'Saved cohort needs integrity review';}$m=$c->details['meta'];return $m['programme']['name'].' · '.$m['programme']['standard'].' v'.$m['programme']['version'].' · '.$m['from'].' to '.$m['to'].' · '.$c->state;}
 public static function actor(User $u):User {$u=PortalActions::actor($u);abort_unless(LearningAccess::manager($u),403);return $u;}
 public static function reference(string $v):bool {return mb_strlen(trim($v))>=5&&mb_strlen($v)<=1000;}
 private static function fields(Cohort $c):array {return $c->only(['id','request_token','created_by','state','version','source_hash','details_hash'])+['approved_at'=>$c->approved_at?->toIso8601String()];}
 private static function rowFields(Row $r):array {return $r->only(['id','cohort_id','apprentice_id','historical_tutor_id','version','source_hash']);}
 private static function sign(Cohort $c,User $u,?array $decision=null):void {$c->proof=['fields'=>self::fields($c),'by'=>$u->id,'auth'=>$u->auth_version,'at'=>now()->toIso8601String(),'decision'=>$decision];$c->save();}
 public static function intact(Cohort $c):bool {
  try{return $c->version>=1&&in_array($c->state,['Draft','Approved','Rejected','Withdrawn'],true)&&$c->details&&ReviewedImports::hash($c->details)===$c->details_hash&&$c->details['format']==='gta-historical-cohort48'&&$c->proof['fields']===self::fields($c)&&($c->state==='Draft'?($c->proof['decision']===null&&$c->approved_at===null):is_array($c->proof['decision']));}catch(\Throwable){return false;}
 }
 public static function rowIntact(Row $r):bool {
  try{return $r->details&&ReviewedImports::hash($r->details)===$r->details_hash&&$r->details['fields']===self::rowFields($r)&&ReviewedImports::hash($r->details['source'])===$r->source_hash;}catch(\Throwable){return false;}
 }
 public static function capture(User $actor,int $programme,string $from,string $to,int $grace,bool $lock=false):array {
  self::actor($actor);
  validator(compact('from','to','grace'),['from'=>'required|date_format:Y-m-d','to'=>'required|date_format:Y-m-d|after_or_equal:from|before_or_equal:today','grace'=>'required|integer|between:0,365'])->validate();
  if(Carbon::parse($from)->diffInDays(Carbon::parse($to))>=366){CentrePlanning::fail('Use an inclusive planned-end interval of at most366 days.');}
  $p=Programme::whereKey($programme)->when($lock,fn($q)=>$q->lockForUpdate())->firstOrFail();
  if(!filled($p->standard_code)||!filled($p->version)||!filled($p->level)){CentrePlanning::fail('Check the programme standard, version and level before a historical comparison.');}
  $base=Apprentice::where('programme_id',$p->id);$missing=(clone$base)->whereNull('planned_end_date')->count();
  $people=(clone$base)->whereDate('planned_end_date','>=',$from)->whereDate('planned_end_date','<=',$to)->orderBy('id')->limit(self::MAX_ROWS+1)->when($lock,fn($q)=>$q->lockForUpdate())->get();
  if($people->count()>self::MAX_ROWS){CentrePlanning::fail('Narrow the cohort; more than500 records match.');}
  $rows=[];
  foreach($people as$a){
   $o=ProgrammeOutcome::where('apprentice_id',$a->id)->when($lock,fn($q)=>$q->lockForUpdate())->first();$context=is_array($o?->context)?$o->context:[];$outcome=CohortInsights::outcome($a,$context);
   $validDates=$a->start_date&&$a->planned_end_date&&$a->planned_end_date->gte($a->start_date)&&$a->start_date->lte(today());
   $cutoff=$validDates?$a->planned_end_date->copy()->addDays($grace)->toDateString():null;
   $mature=(bool)($cutoff&&$cutoff<=today()->toDateString());$afterWindow=(bool)($cutoff&&!$outcome['needs_check']&&(($outcome['end_date']&&$outcome['end_date']>$cutoff)||($outcome['achievement']==='Achieved'&&($context['achievementDate']??'')>$cutoff)));
   $rows[]=['apprentice_id'=>$a->id,'reference'=>$a->reference,'first_name'=>$a->first_name,'last_name'=>$a->last_name,'start_date'=>$a->start_date?->toDateString(),'planned_end_date'=>$a->planned_end_date?->toDateString(),'mature'=>$mature,'observation_cutoff'=>$cutoff,'outcome_after_window'=>$afterWindow,'date_check'=>!$validDates,'outcome'=>$outcome,'outcome_source'=>collect($context)->only(['status','achievement','endDate','achievementDate','sourceRef'])->all(),'profile_version'=>$a->version,'outcome_version'=>$o?->version];
  }
  $meta=['programme'=>['id'=>$p->id,'name'=>$p->name,'standard'=>$p->standard_code,'version'=>$p->version,'level'=>$p->level],'from'=>$from,'to'=>$to,'observation_days'=>$grace,'capture_date'=>today()->toDateString(),'missing_planned_end_outside_cohort'=>$missing,'definition'=>'Current recorded planned-end dates in inclusive interval; outcomes recorded at capture; historical tutor at that recorded planned end requires reviewed evidence','current_tutor_inferred'=>false,'official_qar'=>false,'window_rule'=>'Dated end/achievement events after each recorded planned end plus observation days are shown separately; current source is not reconstructed historical portal knowledge'];
  return ['meta'=>$meta,'rows'=>$rows,'source_hash'=>ReviewedImports::hash(['meta'=>$meta,'rows'=>$rows])];
 }
 public static function create(User $actor,int $auth,int $programme,string $from,string $to,int $grace,string $expected,string $token,string $reference):Cohort {
  if(!Str::isUuid($token)||!self::reference($reference)){CentrePlanning::fail('Use a fresh request and a clear cohort source reference.');}
  return DB::transaction(function()use($actor,$auth,$programme,$from,$to,$grace,$expected,$token,$reference){
   $u=PortalOperations::actor($actor,$auth,true);
   if($old=Cohort::where('request_token',$token)->lockForUpdate()->first()){
    if(self::intact($old)&&$old->created_by===$u->id&&$old->details['creator_auth']===$auth&&$old->source_hash===$expected&&$old->details['meta']['programme']['id']===$programme&&$old->details['meta']['from']===$from&&$old->details['meta']['to']===$to&&$old->details['meta']['observation_days']===$grace&&$old->details['reference']===$reference){return $old;}CentrePlanning::fail('This request belongs to different or damaged work.');
   }
   $source=self::capture($u,$programme,$from,$to,$grace,true);if(!hash_equals($source['source_hash'],$expected)||!$source['rows']){CentrePlanning::fail('The cohort source changed or no records match. Check a fresh preview.');}
   $c=new Cohort(['id'=>(string)Str::uuid(),'request_token'=>$token,'created_by'=>$u->id,'state'=>'Draft','version'=>1,'source_hash'=>$expected]);
   $c->details=['format'=>'gta-historical-cohort48','creator_auth'=>$auth,'meta'=>$source['meta'],'reference'=>$reference,'members'=>array_column($source['rows'],'apprentice_id')];$c->details_hash=ReviewedImports::hash($c->details);$c->proof=[];self::sign($c,$u);
   foreach($source['rows']as$data){$r=new Row(['id'=>(string)Str::uuid(),'cohort_id'=>$c->id,'apprentice_id'=>$data['apprentice_id'],'historical_tutor_id'=>null,'version'=>1,'source_hash'=>ReviewedImports::hash($data)]);$r->details=['fields'=>self::rowFields($r),'source'=>$data,'attribution'=>['reviewed'=>false,'basis'=>null,'reference'=>null,'staff_name'=>null],'history'=>[]];$r->details_hash=ReviewedImports::hash($r->details);$r->save();}
   PortalOperations::event($u,'historical_cohort',$c->id,'historical_cohort_created',['source_hash'=>$expected,'rows'=>count($source['rows']),'current_tutor_inferred'=>false]);return $c;
  },3);
 }
 public static function rows(Cohort $c,bool $lock=false):array {
  if(!self::intact($c)){CentrePlanning::fail('Cohort integrity needs investigation; no live data is substituted.');}
  $rows=Row::where('cohort_id',$c->id)->orderBy('apprentice_id')->when($lock,fn($q)=>$q->lockForUpdate())->get();
  if($rows->pluck('apprentice_id')->all()!==$c->details['members']||$rows->contains(fn($r)=>!self::rowIntact($r))){CentrePlanning::fail('Cohort membership or row proof changed; investigate the saved source.');}
  if($c->state!=='Draft'&&($c->proof['decision']['rows_hash']??null)!==self::rowsHash($rows->all())){CentrePlanning::fail('The reviewed row manifest changed; recover the approved source.');}return $rows->all();
 }
 private static function rowsHash(array $rows):string {return ReviewedImports::hash(array_map(fn($r)=>['id'=>$r->id,'hash'=>$r->details_hash],$rows));}
 public static function edit(User $actor,int $auth,Cohort $record,Row $row,int $version,int $rowVersion,?int $staff,string $basis,string $reference):void {
  if(!in_array($basis,self::BASES,true)||!self::reference($reference)||($basis===self::BASES[0])!==($staff!==null)){CentrePlanning::fail('Record a documented tutor or an explicit shared/unknown attribution with its evidence reference.');}
  DB::transaction(function()use($actor,$auth,$record,$row,$version,$rowVersion,$staff,$basis,$reference){
   $u=PortalOperations::actor($actor,$auth,true);$c=Cohort::whereKey($record->id)->lockForUpdate()->firstOrFail();abort_unless($c->created_by===$u->id,403);$rows=self::rows($c,true);
   if($c->state!=='Draft'||$c->version!==$version||$c->details['creator_auth']!==$auth){CentrePlanning::fail('The draft or current creator account changed.');}
   $r=collect($rows)->firstWhere('id',$row->id);if(!$r||$r->version!==$rowVersion){CentrePlanning::fail('This row belongs to another cohort or changed version.');}
   $target=$staff?User::whereKey($staff)->lockForUpdate()->firstOrFail():null;
   if($target&&!in_array($target->role,[...DirectoryCatalog::STAFF_ROLES,'admin','ceo'],true)){CentrePlanning::fail('Choose a retained staff account. Historical evidence does not grant any portal role.');}
   $history=$r->details['history'];$history[]=['version'=>$r->version,'historical_tutor_id'=>$r->historical_tutor_id,'attribution'=>$r->details['attribution']];$source=$r->details['source'];$r->version++;$r->historical_tutor_id=$staff;
   $r->details=['fields'=>self::rowFields($r),'source'=>$source,'attribution'=>['reviewed'=>true,'basis'=>$basis,'reference'=>$reference,'staff_name'=>$target?->name,'by'=>$u->id,'at'=>now()->toIso8601String()],'history'=>$history];$r->details_hash=ReviewedImports::hash($r->details);$r->save();$c->version++;self::sign($c,$u);
   PortalOperations::event($u,'historical_cohort',$c->id,'historical_attribution_recorded',['row'=>$r->id,'row_version'=>$r->version,'cohort_version'=>$c->version,'documented_staff'=>$staff!==null,'no_live_assignment_changed'=>true]);
  },3);
 }
 public static function editBatch(User $actor,int $auth,Cohort $record,int $version,array $selected,?int $staff,string $basis,string $reference):void {
  if(!$selected||count($selected)>25||array_filter(array_keys($selected),fn($id)=>!is_string($id)||!Str::isUuid($id))||array_filter($selected,fn($v)=>!is_int($v)||$v<1)){CentrePlanning::fail('Select1–25 exact current rows for a checked batch.');}
  DB::transaction(function()use($actor,$auth,$record,$version,$selected,$staff,$basis,$reference){
   $u=PortalOperations::actor($actor,$auth,true);$c=Cohort::whereKey($record->id)->lockForUpdate()->firstOrFail();abort_unless($c->created_by===$u->id,403);self::rows($c,true);if($c->state!=='Draft'||$c->version!==$version){CentrePlanning::fail('The cohort changed; reload before a batch attribution.');}ksort($selected,SORT_STRING);
   foreach($selected as$id=>$rowVersion){$row=Row::whereKey($id)->lockForUpdate()->firstOrFail();self::edit($u,$auth,$c,$row,$c->version,$rowVersion,$staff,$basis,$reference);$c->refresh();}
   PortalOperations::event($u,'historical_cohort',$c->id,'historical_attribution_batch',['rows'=>array_keys($selected),'cohort_version'=>$c->version,'no_live_assignment_changed'=>true]);
  },3);
 }
 public static function decide(User $actor,int $auth,Cohort $record,int $version,string $action,string $reference,bool $ceoOverride=false):void {
  if(!in_array($action,['approve','reject','withdraw'],true)||!self::reference($reference)){CentrePlanning::fail('Choose a reviewed decision and clear reference.');}
  DB::transaction(function()use($actor,$auth,$record,$version,$action,$reference,$ceoOverride){
   $u=PortalOperations::actor($actor,$auth,true);$c=Cohort::whereKey($record->id)->lockForUpdate()->firstOrFail();$rows=self::rows($c,true);
   if($c->version!==$version){CentrePlanning::fail('Cohort or attribution changed. Reopen the exact review.');}
   if($action==='withdraw'){
    if($c->state!=='Approved'){CentrePlanning::fail('Only an approved report can be withdrawn here.');}
   }else{
    if($c->state!=='Draft'){CentrePlanning::fail('Only a current draft can receive this review.');}
    if($u->id===$c->created_by&&!($u->role==='ceo'&&$ceoOverride)){CentrePlanning::fail('Use an independent manager review, or the explicit audited CEO self-review override.');}
    $maker=User::whereKey($c->created_by)->lockForUpdate()->first();if($action==='approve'&&(!$maker||!$maker->active||AccountLifecycle::blocked($maker)||!LearningAccess::manager($maker)||$maker->auth_version!==$c->details['creator_auth'])){CentrePlanning::fail('Creator access changed; recover or recapture the source before approval.');}
    if($action==='approve'){
     if(collect($rows)->contains(fn($r)=>!$r->details['attribution']['reviewed'])){CentrePlanning::fail('Check every attribution, including explicit unknown/shared responsibility.');}
     $m=$c->details['meta'];$current=self::capture($u,$m['programme']['id'],$m['from'],$m['to'],$m['observation_days'],true);
     if($current['source_hash']!==$c->source_hash){CentrePlanning::fail('The programme, cohort membership, dates or outcomes changed since capture. Create a fresh checked cohort; the old draft is retained.');}
    }
   }
   $c->state=match($action){'approve'=>'Approved','reject'=>'Rejected','withdraw'=>'Withdrawn'};$c->version++;if($action==='approve'){$c->approved_at=now()->setMicrosecond(0);}
   $decision=['action'=>$action,'reference'=>$reference,'by'=>$u->id,'auth'=>$auth,'at'=>now()->toIso8601String(),'rows_hash'=>self::rowsHash($rows),'ceo_self_override'=>$u->id===$c->created_by&&$ceoOverride,'previous_decision'=>$c->proof['decision']];self::sign($c,$u,$decision);
   PortalOperations::event($u,'historical_cohort',$c->id,'historical_cohort_'.$action,['version'=>$c->version,'rows_hash'=>$decision['rows_hash'],'ceo_self_override'=>$decision['ceo_self_override']]);
  },3);
 }
 private static function counts():array {return ['cohort'=>0,'mature'=>0,'not_mature'=>0,'date_check'=>0,'achieved'=>0,'not_achieved'=>0,'withdrawn'=>0,'transferred'=>0,'active'=>0,'break'=>0,'awaiting_result'=>0,'after_window'=>0,'unknown'=>0];}
 private static function percentage(array $c):?float {return $c['mature']>=self::MIN_SAMPLE&&($c['active']+$c['break']+$c['awaiting_result']+$c['after_window']+$c['unknown']+$c['date_check'])===0?100*$c['achieved']/$c['mature']:null;}
 public static function report(User $actor,Cohort $c):array {
  self::actor($actor);$c=Cohort::findOrFail($c->id);if($c->state!=='Approved'){CentrePlanning::fail('Only an intact approved cohort can be used for a report or comparison.');}$rows=self::rows($c);$all=self::counts();$groups=[];$unknown=0;
  foreach($rows as$r){$d=$r->details['source'];$attr=$r->details['attribution'];$key=$r->historical_tutor_id?(string)$r->historical_tutor_id:'unknown';if(!$r->historical_tutor_id){$unknown++;}
   $groups[$key]??=['staff_key'=>$key,'staff_name'=>$attr['staff_name']??'Unknown or shared responsibility','counts'=>self::counts()];$one=self::counts();$one['cohort']=1;
   if($d['date_check']){$one['date_check']=1;$one['not_mature']=1;}elseif(!$d['mature']){$one['not_mature']=1;}else{$one['mature']=1;$o=$d['outcome'];$kind=$d['outcome_after_window']?'after_window':match($o['status']){'Completed'=>match($o['achievement']){'Achieved'=>'achieved','Not achieved'=>'not_achieved','Awaiting result'=>'awaiting_result',default=>'unknown'},'Withdrawn'=>'withdrawn','Transferred'=>'transferred','Active'=>'active','Break in learning'=>'break',default=>'unknown'};$one[$kind]=1;}
   foreach($one as$k=>$v){$all[$k]+=$v;$groups[$key]['counts'][$k]+=$v;}
  }
  ksort($groups,SORT_NATURAL);foreach($groups as&$g){$g['achievement_percent']=$g['staff_key']==='unknown'?null:self::percentage($g['counts']);}unset($g);
  return ['format'=>'gta-historical-cohort-aggregate48','cohort_id'=>$c->id,'meta'=>$c->details['meta'],'approved_at'=>$c->approved_at->toIso8601String(),'cohort_source_hash'=>$c->source_hash,'reviewed_rows_hash'=>$c->proof['decision']['rows_hash'],'counts'=>$all,'groups'=>array_values($groups),'unknown_or_shared_attributions'=>$unknown,'minimum_percentage_sample'=>self::MIN_SAMPLE,'achievement_percent'=>self::percentage($all),'denominator'=>'All mature captured cohort members, including withdrawn and transferred records. No exclusion for outcome. Unknown/pending results or dated events after the observation cutoff withhold the percentage.','historical_attribution'=>'Evidence-reviewed tutor at each captured recorded planned end; no current-tutor inference or full teaching-exposure claim','official_qar'=>false,'causal_staff_effectiveness'=>false,'apprentice_identities_in_export'=>false];
 }
 public static function compare(User $actor,Cohort $earlier,Cohort $later):array {
  $a=self::report($actor,$earlier);$b=self::report($actor,$later);$x=$a['meta'];$y=$b['meta'];
  if($earlier->id===$later->id||$x['to']>=$y['from']){CentrePlanning::fail('Choose two distinct non-overlapping cohorts in planned-end date order.');}
  foreach(['standard','version','level']as$k){if($x['programme'][$k]!==$y['programme'][$k]){CentrePlanning::fail('Compare the same programme standard, version and level.');}}
  if($x['observation_days']!==$y['observation_days']||abs(Carbon::parse($x['from'])->diffInDays(Carbon::parse($x['to']))-Carbon::parse($y['from'])->diffInDays(Carbon::parse($y['to'])))>1){CentrePlanning::fail('Use the same observation window and comparable cohort interval lengths (one-day leap-year tolerance).');}
  if($a['counts']['not_mature']||$b['counts']['not_mature']){CentrePlanning::fail('Both captured cohorts must be fully mature with checked dates. Create a fresh checked capture when the observation period has elapsed.');}
  if(array_intersect($earlier->details['members'],$later->details['members'])){CentrePlanning::fail('The same apprentice occurs in both captures. Reconcile cohort membership rather than count them twice.');}
  $groups=[];foreach([$a,$b]as$i=>$report){foreach($report['groups']as$g){$key=$g['staff_key'];$groups[$key]??=['staff_key'=>$key,'staff_name'=>$g['staff_name'],'earlier'=>null,'later'=>null];$groups[$key][$i===0?'earlier':'later']=$g;if($i===1){$groups[$key]['staff_name']=$g['staff_name'];}}}
  ksort($groups,SORT_NATURAL);foreach($groups as&$g){$old=$g['earlier']['achievement_percent']??null;$new=$g['later']['achievement_percent']??null;$g['percentage_point_change']=$old!==null&&$new!==null?$new-$old:null;}unset($g);
  return ['earlier'=>$a,'later'=>$b,'groups'=>array_values($groups),'interpretation'=>'Descriptive comparison of separately reviewed cohorts, not a causal staff ranking or an official achievement return. Unknown/shared responsibility and small/pending results stay visible.'];
 }
}
