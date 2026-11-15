<?php
namespace App\Support;
use App\Models\{ApprenticeAlertDelivery as Delivery,AlertReceiptBatch as Batch,AlertReceiptRecord as Receipt,AlertFollowupCase as Followup,User};
use Illuminate\Support\Facades\{Crypt,DB};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AlertReceiptOperations {
 public const HEADERS=['delivery_id','channel','provider_id','outcome','occurred_at_utc','evidence_kind','source_reference'];
 public const OUTCOMES=['Delivered','Failed','Bounced','Deferred','Unknown'];
 private static function fail(string $message='Receipt source or record changed. Reload and prepare a new checked preview.'):never {throw ValidationException::withMessages(['receipts'=>$message]);}
 public static function allowed(User $u):bool {return LearningAccess::manager($u);}
 private static function actor(User $actor,int $auth):User {$u=User::whereKey($actor->id)->lockForUpdate()->firstOrFail();abort_unless(self::allowed($u)&&$u->auth_version===$auth,403);return $u;}
 private static function event(User $u,string $event,int $version,?Batch $b=null,?Delivery $d=null,?Followup $c=null,?string $note=null):void {
  DB::table('alert_receipt_events')->insert(['actor_id'=>$u->id,'batch_id'=>$b?->id,'delivery_id'=>$d?->id,'case_id'=>$c?->id,'event'=>$event,'record_version'=>$version,'private_note'=>$note!==null?Crypt::encryptString($note):null,'created_at'=>now()]);
 }
 public static function attemptHash(Delivery $d):string {if(!is_array($d->source)||ReviewedImports::hash($d->source)!==$d->source_hash){self::fail('Original attempt source needs checking.');}return ReviewedImports::hash($d->only(['id','apprentice_id','recipient_id','channel','cycle_date','source_hash','recipient_hash','preference_revision','auth_version','config_hash','state','reserved_at','completed_at','provider_id']));}
 public static function records(Delivery $d) {
  return Receipt::where('delivery_id',$d->id)->whereNull('withdrawn_at')->whereIn('batch_id',Batch::where('state','Applied')->select('id'))->orderBy('occurred_at')->orderBy('id')->get();
 }
 public static function setHash(Delivery $d):string {return ReviewedImports::hash(self::records($d)->map(fn($r)=>['id'=>$r->id,'fingerprint'=>$r->fingerprint,'attempt_hash'=>$r->attempt_hash])->values()->all());}
 public static function validRecord(Receipt $r,Delivery $d):bool {
  try {$b=Batch::find($r->batch_id);if(!$b||$b->state!=='Applied'||!$b->checked_at||!$b->checked_by||$b->prepared_by===$b->checked_by||ReviewedImports::hash($b->rows)!==$b->rows_hash){return false;}
   $row=collect($b->rows)->firstWhere('delivery_id',$d->id);return $row&&$r->delivery_id===$d->id&&$r->slot_key===$r->fingerprint&&$row['fingerprint']===$r->fingerprint&&$row['attempt_hash']===self::attemptHash($d)&&$r->attempt_hash===$row['attempt_hash']&&$r->outcome===$row['outcome']&&$r->occurred_at->utc()->format('Y-m-d H:i:s')===$row['occurred_at_utc']&&$r->evidence_kind===$row['evidence_kind']&&$r->source_reference===$row['source_reference'];
  }catch(\Throwable){return false;}
 }
 public static function position(Delivery $d):array {
  try {
   $records=self::records($d);$hash=self::setHash($d);$case=Followup::where('delivery_id',$d->id)->first();$selected=null;
   if($records->contains(fn($r)=>!self::validRecord($r,$d))){return ['outcome'=>'Needs recheck','record'=>null,'case'=>$case,'records_hash'=>$hash,'resolved'=>false];}
   $resolution=$case?->resolution;
   if($resolution&&($resolution['records_hash']??null)===$hash&&($resolution['attempt_hash']??null)===self::attemptHash($d)&&isset($resolution['record_id'])){$selected=$records->firstWhere('id',$resolution['record_id']);}
   if($selected){return ['outcome'=>$selected->outcome,'record'=>$selected,'case'=>$case,'records_hash'=>$hash,'resolved'=>true];}
   $terminal=$records->whereIn('outcome',['Delivered','Failed','Bounced'])->pluck('outcome')->unique();
   if($terminal->count()>1){return ['outcome'=>'Conflict','record'=>null,'case'=>$case,'records_hash'=>$hash,'resolved'=>false];}
   $record=$terminal->isNotEmpty()?$records->whereIn('outcome',['Delivered','Failed','Bounced'])->last():$records->last();
   return ['outcome'=>$record?->outcome??'No receipt','record'=>$record,'case'=>$case,'records_hash'=>$hash,'resolved'=>false];
  }catch(\Throwable){return ['outcome'=>'Needs recheck','record'=>null,'case'=>null,'records_hash'=>null,'resolved'=>false];}
 }
 private static function csvSyntax(string $csv):void {
  $state='field';$length=strlen($csv);
  for($i=0;$i<$length;$i++){$ch=$csv[$i];
   if($state==='quoted'){if($ch==='"'){if($i+1<$length&&$csv[$i+1]==='"'){$i++;}else{$state='after';}}continue;}
   if($ch==='"'){if($state!=='field'){self::fail('Malformed CSV quoting.');}$state='quoted';continue;}
   if($ch===','){$state='field';continue;}
   if($ch==="\n"||$ch==="\r"){if($ch==="\r"){if($i+1>=$length||$csv[$i+1]!=="\n"){self::fail('Use LF or CRLF row endings.');}$i++;}$state='field';continue;}
   if($state==='after'){self::fail('Characters after a quoted CSV field are not allowed.');}$state='unquoted';
  }if($state==='quoted'){self::fail('Unclosed CSV quoted field.');}
 }
 public static function parse(string $csv):array {
  if(strlen($csv)>250000||str_contains($csv,"\0")||!mb_check_encoding($csv,'UTF-8')){self::fail('Use UTF-8 CSV up to 250,000 bytes without NUL characters.');}
  $csv=preg_replace('/^\xEF\xBB\xBF/','',$csv);self::csvSyntax($csv);$f=fopen('php://temp','r+');fwrite($f,$csv);rewind($f);$header=fgetcsv($f,null,',','"','');if($header!==self::HEADERS){fclose($f);self::fail('CSV headers/order must exactly match the supplied template.');}
  $rows=[];$seen=[];
  while(($values=fgetcsv($f,null,',','"',''))!==false) {
   if(count($rows)>=250||count($values)!==count(self::HEADERS)||in_array(null,$values,true)){fclose($f);self::fail('Use 1–250 complete rows, exactly seven fields per row, no blank lines.');}
   $v=array_combine(self::HEADERS,array_map('trim',$values));$number=count($rows)+2;
   if(!Str::isUuid($v['delivery_id'])||isset($seen[$v['delivery_id']])||!in_array($v['channel'],['sms','email'],true)||!preg_match('/^[A-Za-z0-9_-]{1,96}$/D',$v['provider_id'])||!in_array($v['outcome'],self::OUTCOMES,true)||mb_strlen($v['source_reference'])>1000||$v['source_reference']===''){fclose($f);self::fail('Check row '.$number.': IDs/channel/outcome/source, and use each delivery only once in a batch.');}
   $date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$v['occurred_at_utc'],new \DateTimeZone('UTC'));
   if(!$date||$date->format('Y-m-d H:i:s')!==$v['occurred_at_utc']||$date->getTimestamp()>now()->timestamp){fclose($f);self::fail('Check row '.$number.': actual non-future event timestamp is required in UTC.');}
   $kinds=$v['channel']==='sms'?['sms_delivery_report','provider_report']:['email_delivery_report','bounce_report','provider_report'];
   if(!in_array($v['evidence_kind'],$kinds,true)||($v['channel']==='sms'&&$v['outcome']==='Bounced')||($v['evidence_kind']==='bounce_report'&&$v['outcome']!=='Bounced')){fclose($f);self::fail('Check row '.$number.': evidence kind/outcome must match the channel.');}
   $seen[$v['delivery_id']]=true;$rows[]=$v;
  }fclose($f);if(!$rows){self::fail('Add at least one complete evidence row.');}return $rows;
 }
 private static function row(Delivery $d,array $v):array {
  if($d->channel!==$v['channel']||$d->state!=='Accepted'||!$d->reserved_at||!filled($d->provider_id)||!hash_equals($d->provider_id,$v['provider_id'])||$v['occurred_at_utc']<$d->reserved_at->utc()->format('Y-m-d H:i:s')){self::fail('A row does not match a reserved Accepted attempt, its exact stored provider reference/channel, or a valid event time. Uncertain attempts need the existing investigation workflow.');}
  $fingerprint=ReviewedImports::hash([$d->id,$v['channel'],$v['provider_id'],$v['outcome'],$v['occurred_at_utc'],$v['evidence_kind']]);
  if(Receipt::where('slot_key',$fingerprint)->exists()){self::fail('This delivery event is already active. Do not re-import the same evidence.');}
  return [...$v,'attempt_hash'=>self::attemptHash($d),'records_hash'=>self::setHash($d),'fingerprint'=>$fingerprint];
 }
 public static function preview(User $actor,int $auth,string $request,string $csv,string $source):Batch {
  $values=self::parse($csv);
  return DB::transaction(function()use($actor,$auth,$request,$values,$source):Batch {
   $u=self::actor($actor,$auth);$old=Batch::where('request_token',$request)->first();
   if($old){$raw=array_map(fn($v)=>collect($v)->only(self::HEADERS)->all(),$old->rows);if($old->prepared_by===$u->id&&$raw===$values&&$old->source_reference===$source){return $old;}self::fail('Request token already used. Reload before preparing different evidence.');}
   $deliveries=Delivery::whereIn('id',array_column($values,'delivery_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');if($deliveries->count()!==count($values)){self::fail('At least one delivery ID is unknown. No preview saved.');}
   $rows=[];foreach($values as$v){$rows[]=self::row($deliveries[$v['delivery_id']],$v);}
   $b=new Batch(['request_token'=>$request,'prepared_by'=>$u->id,'state'=>'Preview','version'=>1,'rows'=>$rows,'rows_hash'=>ReviewedImports::hash($rows),'source_reference'=>$source]);$b->id=(string)Str::uuid();$b->save();self::event($u,'receipt_previewed',1,$b);return $b;
  });
 }
 private static function changedEvidence(Delivery $d,User $u,Batch $b):void {
  $c=Followup::where('delivery_id',$d->id)->lockForUpdate()->first();$position=self::position($d);
  if(!$c&&in_array($position['outcome'],['Failed','Bounced','Unknown','Conflict','Needs recheck'],true)){$c=Followup::create(['delivery_id'=>$d->id,'state'=>'Open','version'=>1]);self::event($u,'followup_opened',1,$b,$d,$c);}
  elseif($c){$c->state='Open';$c->proposal=null;$c->resolution=null;$c->checked_by=null;$c->closed_at=null;$c->version++;$c->save();self::event($u,'new_evidence_reopened_case',$c->version,$b,$d,$c);}
 }
 public static function review(User $actor,int $auth,Batch $record,int $version,string $decision,string $note):void {
  abort_unless(in_array($decision,['apply','reject'],true),422);
  DB::transaction(function()use($actor,$auth,$record,$version,$decision,$note):void {
   $u=self::actor($actor,$auth);$b=Batch::whereKey($record->id)->lockForUpdate()->firstOrFail();
   if($b->version!==$version||$b->state!=='Preview'||$b->prepared_by===$u->id){self::fail('Use an unchanged preview checked by a different administrator/CEO.');}
   if($decision==='apply'){
    if($b->created_at->lessThanOrEqualTo(now()->subDay())||ReviewedImports::hash($b->rows)!==$b->rows_hash){self::fail('Preview expired or cannot be verified. Prepare a fresh preview.');}
    $rows=$b->rows;$deliveries=Delivery::whereIn('id',array_column($rows,'delivery_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    foreach($rows as$row){$d=$deliveries->get($row['delivery_id']);if(!$d||self::row($d,collect($row)->only(self::HEADERS)->all())!==$row){self::fail();}}
    foreach($rows as$row){$r=new Receipt(['batch_id'=>$b->id,'delivery_id'=>$row['delivery_id'],'outcome'=>$row['outcome'],'occurred_at'=>$row['occurred_at_utc'],'evidence_kind'=>$row['evidence_kind'],'source_reference'=>$row['source_reference'],'attempt_hash'=>$row['attempt_hash'],'fingerprint'=>$row['fingerprint'],'slot_key'=>$row['fingerprint'],'created_at'=>now()]);$r->id=(string)Str::uuid();$r->save();}
    $b->state='Applied';$b->checked_by=$u->id;$b->checked_at=now();$b->review_note=$note;$b->version++;$b->save();foreach($deliveries as$d){self::changedEvidence($d,$u,$b);}
   }else{$b->state='Rejected';$b->checked_by=$u->id;$b->checked_at=now();$b->review_note=$note;$b->version++;$b->save();}
   self::event($u,$decision==='apply'?'receipt_batch_applied':'receipt_batch_rejected',$b->version,$b,null,null,$note);
  });
 }
 public static function withdraw(User $actor,int $auth,Batch $record,int $version,string $note):void {
  DB::transaction(function()use($actor,$auth,$record,$version,$note):void {
   $u=self::actor($actor,$auth);$b=Batch::whereKey($record->id)->lockForUpdate()->firstOrFail();
   if($b->version!==$version||!in_array($b->state,['Preview','Applied'],true)||($b->state==='Applied'&&$b->prepared_by===$u->id)){self::fail('Use a current preview, or an applied batch corrected by a different administrator/CEO.');}
   $deliveries=$b->state==='Applied'?Delivery::whereIn('id',Receipt::where('batch_id',$b->id)->select('delivery_id'))->orderBy('id')->lockForUpdate()->get():collect();
   Receipt::where('batch_id',$b->id)->whereNull('withdrawn_at')->update(['withdrawn_at'=>now(),'slot_key'=>null]);$b->state='Withdrawn';$b->withdrawn_at=now();$b->review_note=$note;$b->version++;$b->save();foreach($deliveries as$d){self::changedEvidence($d,$u,$b);}self::event($u,'receipt_batch_withdrawn',$b->version,$b,null,null,$note);
  });
 }
 public static function saveCase(User $actor,int $auth,Delivery $record,int $version,?int $owner,?string $due,string $note):Followup {
  return DB::transaction(function()use($actor,$auth,$record,$version,$owner,$due,$note):Followup {
   $u=self::actor($actor,$auth);$d=Delivery::whereKey($record->id)->lockForUpdate()->firstOrFail();$c=Followup::where('delivery_id',$d->id)->lockForUpdate()->first();
   if(($c?->version??0)!==$version){self::fail('Follow-up changed. Reload first.');}
   if($owner){$target=User::whereKey($owner)->lockForUpdate()->first();if(!$target||!self::allowed($target)){self::fail('Choose an active administrator/CEO owner.');}}
   if($due&&(!CareersFollowup::date($due)||$due<today()->toDateString())){self::fail('Choose an explicit next follow-up date today or later.');}
   $c??=new Followup(['delivery_id'=>$d->id,'version'=>0]);$c->owner_id=$owner;$c->due_on=$due;$c->state='Open';$c->proposal=null;$c->resolution=null;$c->checked_by=null;$c->closed_at=null;$c->version++;$c->save();self::event($u,'followup_saved',$c->version,null,$d,$c,$note);return $c;
  });
 }
 public static function proposeClose(User $actor,int $auth,Followup $record,int $version,?string $selected,string $note):void {
  DB::transaction(function()use($actor,$auth,$record,$version,$selected,$note):void {
   $u=self::actor($actor,$auth);$d=Delivery::whereKey($record->delivery_id)->lockForUpdate()->firstOrFail();$c=Followup::whereKey($record->id)->lockForUpdate()->firstOrFail();$position=self::position($d);
   if($c->version!==$version||$c->state!=='Open'){self::fail('Use the current open case.');}
   if(($position['outcome']==='Conflict'&&!$selected)||($selected&&!self::records($d)->contains(fn($r)=>$r->id===$selected&&self::validRecord($r,$d)))){self::fail('A conflict needs an explicit current source record. No inferred delivery result.');}
   $c->proposal=['prepared_by'=>$u->id,'auth_version'=>$u->auth_version,'record_id'=>$selected,'attempt_hash'=>self::attemptHash($d),'records_hash'=>self::setHash($d),'note'=>$note];$c->state='Awaiting check';$c->version++;$c->save();self::event($u,'followup_close_proposed',$c->version,null,$d,$c,$note);
  });
 }
 public static function checkClose(User $actor,int $auth,Followup $record,int $version,string $decision,string $note):void {
  abort_unless(in_array($decision,['close','return'],true),422);
  DB::transaction(function()use($actor,$auth,$record,$version,$decision,$note):void {
   $u=self::actor($actor,$auth);$d=Delivery::whereKey($record->delivery_id)->lockForUpdate()->firstOrFail();$c=Followup::whereKey($record->id)->lockForUpdate()->firstOrFail();$p=$c->proposal;
   if($c->version!==$version||$c->state!=='Awaiting check'||!$p||$p['prepared_by']===$u->id){self::fail('Use a current proposal checked by a different administrator/CEO.');}
   if($decision==='close') {
    $preparer=User::find($p['prepared_by']);if(!$preparer||!self::allowed($preparer)||$preparer->auth_version!==$p['auth_version']||$p['attempt_hash']!==self::attemptHash($d)||$p['records_hash']!==self::setHash($d)){self::fail();}
    if($p['record_id']&&!self::records($d)->contains(fn($r)=>$r->id===$p['record_id']&&self::validRecord($r,$d))){self::fail();}
    $c->resolution=$p;$c->state='Closed';$c->checked_by=$u->id;$c->closed_at=now();
   }else{$c->state='Open';$c->proposal=null;}
   $c->version++;$c->save();self::event($u,$decision==='close'?'followup_independently_closed':'followup_returned',$c->version,null,$d,$c,$note);
  });
 }
}
