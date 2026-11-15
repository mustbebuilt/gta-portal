<?php
namespace App\Support;
use App\Models\{Apprentice,DestinationContact as Contact,DestinationProposal as Proposal,ProgrammeOutcome,User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LeaverDestinations {
 public const METHODS=['Phone','Email','In person','Existing record'];
 public const RESULTS=['Reached','No reply','Declined'];
 public const DESTINATION_KEYS=['destination','destinationBasis','destinationDate','destinationRef'];
 public static function choices():array {return json_decode(file_get_contents(resource_path('data/retention-choices.json')),true,512,JSON_THROW_ON_ERROR);}
 public static function allowed(User $u):bool {return $u->active&&(LearningAccess::manager($u)||StaffDuties::has($u,'careers_adviser'));}
 public static function can(User $u,Apprentice $a):bool {return LearningAccess::manager($u)||StaffDuties::assigned($u,$a,'careers_adviser');}
 private static function fail(string $message='The source or record changed. Reload and prepare a new checked proposal.'):never {throw ValidationException::withMessages(['destinations'=>$message]);}
 private static function actor(User $actor,int $auth,Apprentice $a,bool $manager=false):User {
  $u=User::whereKey($actor->id)->lockForUpdate()->firstOrFail();abort_unless($u->auth_version===$auth&&($manager?LearningAccess::manager($u):self::can($u,$a)),403);return $u;
 }
 private static function outcome(Apprentice $a):?ProgrammeOutcome {return ProgrammeOutcome::where('apprentice_id',$a->id)->when(DB::transactionLevel()>0,fn($q)=>$q->lockForUpdate())->first();}
 public static function anchor(Apprentice $a,?ProgrammeOutcome $o):?array {
  $c=$o?->context??[];$end=CareersFollowup::date($c['endDate']??null);
  if(!$o||!in_array($c['status']??'', ['Completed','Withdrawn','Transferred'],true)||!$end||!$a->start_date||$end<$a->start_date->toDateString()||$end>today()->toDateString()||!filled($c['sourceRef']??null)){return null;}
  return ['apprentice_id'=>$a->id,'reference'=>$a->reference,'start_date'=>$a->start_date->toDateString(),'programme_id'=>$a->programme_id,'status'=>$c['status'],'end_date'=>$end,'status_source_hash'=>hash('sha256',$c['sourceRef'])];
 }
 private static function event(Apprentice $a,User $u,string $event,int $version,?Contact $contact=null,?Proposal $proposal=null):void {
  DB::table('destination_events')->insert(['apprentice_id'=>$a->id,'actor_id'=>$u->id,'contact_id'=>$contact?->id,'proposal_id'=>$proposal?->id,'event'=>$event,'record_version'=>$version,'created_at'=>now()]);
 }
 public static function contactCurrent(Contact $c):bool {
  try{$detail=$c->detail;if(!is_array($detail)||!is_string($detail['reference']??null)||!filled($detail['reference'])){return false;}$a=Apprentice::find($c->apprentice_id);$anchor=$a?self::anchor($a,self::outcome($a)):null;return $anchor&&!$c->voided_at&&ReviewedImports::hash($anchor)===$c->source_hash&&ReviewedImports::hash($c->source)===$c->source_hash;}catch(\Throwable){return false;}
 }
 public static function recordContact(User $actor,int $auth,Apprentice $profile,array $data):Contact {
  return DB::transaction(function()use($actor,$auth,$profile,$data):Contact {
   $a=Apprentice::whereKey($profile->id)->lockForUpdate()->firstOrFail();$u=self::actor($actor,$auth,$a);$o=self::outcome($a);$anchor=self::anchor($a,$o);
   if(!$anchor||$o->version!==(int)$data['outcome_version']){self::fail('Record a current sourced programme end before contact follow-up. Reload any changed outcome.');}
   if($data['contacted_on']<$anchor['end_date']||$data['contacted_on']>today()->toDateString()||!CareersFollowup::date($data['contacted_on'])||!in_array($data['method'],self::METHODS,true)||!in_array($data['result'],self::RESULTS,true)){self::fail('Use an actual dated contact on or after the programme end.');}
   $next=$data['next_followup_on']??null;if(($data['result']==='No reply'&&!$next)||($next&&(!CareersFollowup::date($next)||$next<today()->toDateString()||$next<$data['contacted_on']))){self::fail('No reply needs an explicit next follow-up date today or later. Check any other planned date.');}
   $detail=['note'=>trim($data['note']??''),'reference'=>trim($data['reference'])];
   $old=Contact::where('request_token',$data['request_token'])->first();
   if($old){if($old->recorded_by===$u->id&&$old->apprentice_id===$a->id&&$old->source_hash===ReviewedImports::hash($anchor)&&$old->contacted_on->toDateString()===$data['contacted_on']&&$old->method===$data['method']&&$old->result===$data['result']&&$old->next_followup_on?->toDateString()===$next&&$old->detail===$detail){return $old;}self::fail('This contact request was already used. Reload before recording a different contact.');}
   $c=Contact::create(['request_token'=>$data['request_token'],'apprentice_id'=>$a->id,'recorded_by'=>$u->id,'contacted_on'=>$data['contacted_on'],'method'=>$data['method'],'result'=>$data['result'],'next_followup_on'=>$next,'detail'=>$detail,'source'=>$anchor,'source_hash'=>ReviewedImports::hash($anchor),'version'=>1]);self::event($a,$u,'contact_recorded',1,$c);return $c;
  });
 }
 public static function voidContact(User $actor,int $auth,Contact $record,int $version,string $reason):void {
  DB::transaction(function()use($actor,$auth,$record,$version,$reason):void {
   $a=Apprentice::whereKey($record->apprentice_id)->lockForUpdate()->firstOrFail();$u=self::actor($actor,$auth,$a);$c=Contact::whereKey($record->id)->lockForUpdate()->firstOrFail();
   if($c->version!==$version||$c->voided_at){self::fail('Contact changed or already voided. Reload first.');}
   $c->voided_at=now();$c->voided_by=$u->id;$c->void_reason=$reason;$c->version++;$c->save();self::event($a,$u,'contact_voided',$c->version,$c);
  });
 }
 private static function source(Apprentice $a,ProgrammeOutcome $o):?array {
  $anchor=self::anchor($a,$o);return $anchor?['anchor'=>$anchor,'outcome_id'=>$o->id,'outcome_version'=>$o->version,'context_hash'=>ReviewedImports::hash($o->context)]:null;
 }
 public static function propose(User $actor,int $auth,Apprentice $profile,array $data):Proposal {
  return DB::transaction(function()use($actor,$auth,$profile,$data):Proposal {
   $a=Apprentice::whereKey($profile->id)->lockForUpdate()->firstOrFail();$u=self::actor($actor,$auth,$a);$o=self::outcome($a);$source=$o?self::source($a,$o):null;
   if(!$source||$o->version!==(int)$data['outcome_version']){self::fail('A current sourced end record is required. Reload before proposing a destination.');}
   $choices=self::choices();if($data['destination']==='Unknown'||!in_array($data['destination'],$choices['destination'],true)||!in_array($data['basis'],['Reported','Verified'],true)||!CareersFollowup::date($data['observed_on'])||$data['observed_on']<$source['anchor']['end_date']||$data['observed_on']>today()->toDateString()||!filled($data['reference'])){self::fail('Choose an existing destination category with an actual source/date after the programme ended. Keep Reported separate from Verified.');}
   $payload=['destination'=>$data['destination'],'destinationBasis'=>$data['basis'],'destinationDate'=>$data['observed_on'],'destinationRef'=>trim($data['reference'])];
   $old=Proposal::where('request_token',$data['request_token'])->first();if($old){if($old->prepared_by===$u->id&&$old->apprentice_id===$a->id&&$old->source_hash===ReviewedImports::hash($source)&&$old->payload_hash===ReviewedImports::hash($payload)){return $old;}self::fail('This destination request was already used. Reload before making a different proposal.');}
   $x=new Proposal(['request_token'=>$data['request_token'],'apprentice_id'=>$a->id,'outcome_id'=>$o->id,'prepared_by'=>$u->id,'state'=>'Pending','version'=>1,'payload'=>$payload,'payload_hash'=>ReviewedImports::hash($payload),'source'=>$source,'source_hash'=>ReviewedImports::hash($source)]);$x->id=(string)Str::uuid();$x->save();self::event($a,$u,'destination_proposed',1,null,$x);return $x;
  });
 }
 public static function proposalCurrent(Proposal $x):bool {
  try{$a=Apprentice::find($x->apprentice_id);$o=$a?self::outcome($a):null;$source=$a&&$o?self::source($a,$o):null;return $source&&$source['outcome_id']===$x->outcome_id&&ReviewedImports::hash($source)===$x->source_hash&&ReviewedImports::hash($x->source)===$x->source_hash&&ReviewedImports::hash($x->payload)===$x->payload_hash;}catch(\Throwable){return false;}
 }
 private static function saveContext(ProgrammeOutcome $o,array $context,User $u):void {
  $h=$o->history??[];$h[]=['before'=>$o->context,'by'=>$u->id,'at'=>now()->toIso8601String()];$o->context=$context;$o->history=$h;$o->version++;$o->save();
  DB::table('learning_events')->insert(['user_id'=>$u->id,'apprentice_id'=>$o->apprentice_id,'kind'=>'outcome_context','record_id'=>$o->id,'event'=>'destination_context_updated','created_at'=>now()]);
 }
 public static function review(User $actor,int $auth,Proposal $record,int $version,string $decision,string $note):void {
  abort_unless(in_array($decision,['apply','return'],true),422);
  DB::transaction(function()use($actor,$auth,$record,$version,$decision,$note):void {
   $a=Apprentice::whereKey($record->apprentice_id)->lockForUpdate()->firstOrFail();$u=self::actor($actor,$auth,$a,true);$x=Proposal::whereKey($record->id)->lockForUpdate()->firstOrFail();$o=self::outcome($a);
   if($x->version!==$version||$x->state!=='Pending'||$x->prepared_by===$u->id){self::fail('Use a current pending proposal checked by a different administrator/CEO.');}
   if($decision==='apply') {
    if(!self::proposalCurrent($x)){self::fail();}$before=[];foreach(self::DESTINATION_KEYS as$key){$before[$key]=['present'=>array_key_exists($key,$o->context),'value'=>$o->context[$key]??null];}
    $context=array_merge($o->context,$x->payload);self::saveContext($o,$context,$u);$x->receipt=['before'=>$before,'outcome_id'=>$o->id,'outcome_version'=>$o->version,'context_hash'=>ReviewedImports::hash($o->context),'payload_hash'=>$x->payload_hash];$x->state='Applied';
   }else{$x->state='Returned';}
   $x->review_note=$note;$x->checked_by=$u->id;$x->checked_at=now();$x->version++;$x->save();self::event($a,$u,$decision==='apply'?'destination_applied':'destination_returned',$x->version,null,$x);
  });
 }
 public static function appliedCurrent(Proposal $x):bool {
  try{$o=ProgrammeOutcome::whereKey($x->outcome_id)->where('apprentice_id',$x->apprentice_id)->first();$a=Apprentice::find($x->apprentice_id);$r=$x->receipt;return $x->state==='Applied'&&$o&&$a&&self::anchor($a,$o)===$x->source['anchor']&&$r&&$r['outcome_id']===$o->id&&$r['outcome_version']===$o->version&&$r['context_hash']===ReviewedImports::hash($o->context)&&$r['payload_hash']===$x->payload_hash&&ReviewedImports::hash($x->payload)===$x->payload_hash;}catch(\Throwable){return false;}
 }
 public static function withdraw(User $actor,int $auth,Proposal $record,int $version,string $reason):void {
  DB::transaction(function()use($actor,$auth,$record,$version,$reason):void {
   $a=Apprentice::whereKey($record->apprentice_id)->lockForUpdate()->firstOrFail();$u=self::actor($actor,$auth,$a);$x=Proposal::whereKey($record->id)->lockForUpdate()->firstOrFail();$o=self::outcome($a);
   if($x->version!==$version||!in_array($x->state,['Pending','Applied'],true)){self::fail('Proposal changed or already closed. Reload first.');}
   if($x->state==='Applied') {
    abort_unless(LearningAccess::manager($u)&&$u->id!==$x->prepared_by,403);
    if(!self::appliedCurrent($x)){self::fail('Later outcome changes prevent automatic reversal. Reconcile the original reporting context without overwriting newer facts.');}
    $before=$x->receipt['before'];if(array_keys($before)!==self::DESTINATION_KEYS){self::fail();}$context=$o->context;foreach($before as$key=>$old){if(!in_array($key,self::DESTINATION_KEYS,true)){self::fail();}if($old['present']){$context[$key]=$old['value'];}else{unset($context[$key]);}}
    self::saveContext($o,$context,$u);
   }
   $x->state='Withdrawn';$x->withdrawn_at=now();$x->review_note=$reason;$x->version++;$x->save();self::event($a,$u,'destination_withdrawn',$x->version,null,$x);
  });
 }
 public static function rows(User $u):\Illuminate\Support\Collection {
  abort_unless(self::allowed($u),403);$people=Apprentice::with('careersAdviser')->when(!LearningAccess::manager($u),fn($q)=>$q->where('careers_adviser_id',$u->id))->orderBy('last_name')->get();$outcomes=ProgrammeOutcome::whereIn('apprentice_id',$people->pluck('id'))->get()->keyBy('apprentice_id');
  $contacts=Contact::whereIn('apprentice_id',$people->pluck('id'))->whereNull('voided_at')->orderByDesc('contacted_on')->orderByDesc('id')->get()->groupBy('apprentice_id');$proposals=Proposal::whereIn('apprentice_id',$people->pluck('id'))->where('state','Pending')->get()->groupBy('apprentice_id');$applied=Proposal::whereIn('apprentice_id',$people->pluck('id'))->where('state','Applied')->get()->groupBy('apprentice_id');$rows=collect();
  foreach($people as$a){$o=$outcomes->get($a->id);$c=$o?->context??[];if(!in_array($c['status']??'', ['Completed','Withdrawn','Transferred'],true)){continue;}
   $anchor=self::anchor($a,$o);$contact=$contacts->get($a->id)?->first();$current=$contact&&self::contactCurrent($contact);$next=$current?$contact->next_followup_on?->toDateString():null;$destination=$c['destination']??'Unknown';$basis=$c['destinationBasis']??'Unknown';
   $known=in_array($destination,array_diff(self::choices()['destination'],['Unknown']),true)&&in_array($basis,['Reported','Verified'],true)&&CareersFollowup::date($c['destinationDate']??null)&&($c['destinationDate']??'')>=($anchor['end_date']??'9999')&&($c['destinationDate']??'')<=today()->toDateString()&&filled($c['destinationRef']??null);
   $pending=$proposals->get($a->id,collect());$held=$pending->filter(fn($x)=>!self::proposalCurrent($x))->count();$owner=$a->careersAdviser; $ownerOk=$owner&&StaffDuties::has($owner,'careers_adviser');
   $flags=['source'=>!$anchor||($contact&&!$current)||$held>0||(($c['destination']??'Unknown')!=='Unknown'&&!$known),'unknown'=>!$known,'reported'=>$known&&$basis==='Reported','pending'=>$pending->isNotEmpty(),'overdue'=>$next&&$next<today()->toDateString(),'today'=>$next===today()->toDateString(),'assignments'=>!$ownerOk];
   $rows->push(['apprentice'=>$a,'outcome'=>$o,'destination'=>$known?$destination:'Unknown / needs source','basis'=>$known?$basis:'Unknown','destination_date'=>$known?$c['destinationDate']:null,'checked'=>$applied->get($a->id,collect())->contains(fn($x)=>self::appliedCurrent($x)),'owner'=>$ownerOk?$owner->name:'Adviser assignment needs checking','next'=>$next,'last_contact'=>$contact?->contacted_on?->toDateString(),'contact_result'=>$contact?->result??'Not recorded','pending'=>$pending->count(),'held'=>$held,'flags'=>$flags]);
  }return $rows->sortBy(fn($r)=>($r['flags']['source']?'0':($r['flags']['overdue']?'1':'2')).($r['next']??'9999').$r['apprentice']->reference)->values();
 }
 public static function csvCell(mixed $value):string {$text=(string)($value??'');return preg_match('/^[\s\x00-\x1f]*[=+@\-]/u',$text)||preg_match('/^[\t\r\n]/',$text)?"'".$text:$text;}
}
