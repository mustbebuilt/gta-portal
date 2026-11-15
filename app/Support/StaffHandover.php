<?php
namespace App\Support;
use App\Models\{User,Apprentice,StaffDuty,StaffHandoverBatch as Batch};
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
class StaffHandover {
 public const SLOTS=AssignmentChecks::ROLES;
 private static function fail(string $m='Assignments or staff access changed. Prepare a fresh preview.'):never {throw ValidationException::withMessages(['handover'=>$m]);}
 public static function recipientAllowed(User $u):bool {return $u->active&&in_array($u->role,DirectoryCatalog::STAFF_ROLES,true);}
 public static function eligible(User $u,string $slot):bool {return isset(self::SLOTS[$slot])&&$u->active&&($slot==='tutor'?$u->role==='tutor':StaffDuties::has($u,$slot));}
 public static function staffSeal(User $u):array {return ['id'=>$u->id,'name'=>$u->name,'hash'=>ReviewedImports::hash(['user'=>$u->only(['id','name','email','role','active','record_version','auth_version','subject_scope']),'duties'=>StaffDuty::find($u->id)?->getAttributes(),'support'=>SupportAccess::settings($u)])];}
 private static function event(Batch $b,User $u,string $event,int $version,array $payload=[]):void {DB::table('staff_handover_events')->insert(['batch_id'=>$b->id,'actor_id'=>$u->id,'event'=>$event,'record_version'=>$version,'payload'=>Crypt::encryptString(json_encode($payload,JSON_THROW_ON_ERROR)),'created_at'=>now()]);}
 private static function actor(User $u,int $auth):User {$u=User::whereKey($u->id)->lockForUpdate()->firstOrFail();abort_unless(LearningAccess::manager($u)&&$u->auth_version===$auth,403);return $u;}
 private static function capture(string $slot,?int $from,int $target,array $ids,bool $lock=false):array {
  if(!isset(self::SLOTS[$slot])||count($ids)<1||count($ids)>100||count(array_unique($ids))!==count($ids)){self::fail('Choose one valid assignment and 1–100 distinct apprentice records.');}sort($ids,SORT_NUMERIC);
  $users=User::whereIn('id',array_filter([$from,$target]))->orderBy('id')->when($lock,fn($q)=>$q->lockForUpdate())->get()->keyBy('id');$to=$users->get($target);$old=$from?$users->get($from):null;
  if(!$to||!self::eligible($to,$slot)||($from&&!$old)||$from===$target){self::fail('Choose a different active staff member eligible for this exact assignment.');}
  $field=$slot.'_id';$people=Apprentice::whereIn('id',$ids)->orderBy('id')->when($lock,fn($q)=>$q->lockForUpdate())->get();if($people->count()!==count($ids)){self::fail();}$rows=[];
  foreach($people as$a){if($a->$field!==$from){self::fail('One selected apprentice no longer has the chosen source assignment.');}$rows[]=['id'=>$a->id,'reference'=>$a->reference,'name'=>trim($a->first_name.' '.$a->last_name),'version'=>$a->version,'profile_hash'=>ReviewedImports::hash($a->getAttributes()),'before_links'=>$a->only(AssignmentHistory::KEYS)];}
  $support=SupportAccess::settings($to);
  return ['slot'=>$slot,'from'=>$old?self::staffSeal($old):null,'target'=>self::staffSeal($to),'rows'=>$rows,'support_impact'=>in_array($support['duty'],['member','lead'],true)&&$support['scope']==='assigned','warning'=>'Only this assignment changes. Other duties, group access, private support permissions and fixed review participants follow their existing rules; signed records and old evidence are retained.'];
 }
 public static function intact(Batch $b):bool {try{$s=$b->source;return $s&&ReviewedImports::hash($s)===$b->source_hash&&$s['slot']===$b->slot&&$s['target']['id']===$b->target_user_id&&($s['from']['id']??null)===$b->from_user_id&&$s['creator']['id']===$b->created_by&&($s['expires_at']??null)===$b->expires_at->toIso8601String();}catch(\Throwable){return false;}}
 public static function fresh(Batch $b,bool $lock=false):bool {
  try{if(!self::intact($b)||$b->state!=='Pending'||now()->greaterThanOrEqualTo($b->expires_at)){return false;}$s=$b->source;$m=User::find($b->created_by);if(!$m||!LearningAccess::manager($m)||$m->auth_version!==$s['creator']['auth_version']){return false;}$now=self::capture($b->slot,$b->from_user_id,$b->target_user_id,array_column($s['rows'],'id'),$lock);unset($s['creator'],$s['reason'],$s['expires_at']);return ReviewedImports::hash($now)===ReviewedImports::hash($s);}catch(\Throwable){return false;}
 }
 public static function prepare(User $actor,int $auth,string $slot,?int $from,int $target,array $ids,string $reason):Batch {
  return DB::transaction(function()use($actor,$auth,$slot,$from,$target,$ids,$reason):Batch {
   User::whereIn('id',array_filter([$actor->id,$from,$target]))->orderBy('id')->lockForUpdate()->get();$m=self::actor($actor,$auth);$s=self::capture($slot,$from,$target,$ids,true);$s['creator']=['id'=>$m->id,'auth_version'=>$m->auth_version];$s['reason']=$reason;$expires=now()->addDay()->setMicrosecond(0);$s['expires_at']=$expires->toIso8601String();
   $b=new Batch(['created_by'=>$m->id,'target_user_id'=>$target,'from_user_id'=>$from,'slot'=>$slot,'state'=>'Pending','version'=>1,'source'=>$s,'source_hash'=>ReviewedImports::hash($s),'expires_at'=>$expires]);$b->id=(string)Str::uuid();$b->save();self::event($b,$m,'preview_prepared',1,['source_hash'=>$b->source_hash,'rows'=>count($s['rows'])]);return $b;
  },3);
 }
 public static function applied(Batch $b):bool {
  try{$p=$b->receipt;return self::intact($b)&&$b->state==='Applied'&&$b->reviewed_by&&$b->reviewed_by!==$b->created_by&&$b->reviewed_at&&$p&&$p['source_hash']===$b->source_hash&&$p['version']===$b->version&&$p['by']===$b->reviewed_by&&$p['at']===$b->reviewed_at->toIso8601String()&&count($p['after'])===count($b->source['rows']);}catch(\Throwable){return false;}
 }
 public static function decide(User $actor,int $auth,Batch $record,int $version,string $decision,string $note):void {
  DB::transaction(function()use($actor,$auth,$record,$version,$decision,$note):void {
   // Lock users before the batch/profile locks. Native deadlocks retry DB-only work; no sends here.
   User::whereIn('id',array_filter([$actor->id,$record->created_by,$record->from_user_id,$record->target_user_id]))->orderBy('id')->lockForUpdate()->get();$m=self::actor($actor,$auth);$b=Batch::whereKey($record->id)->lockForUpdate()->firstOrFail();
   if($b->state!=='Pending'||$b->version!==$version||!self::intact($b)||!in_array($decision,['apply','reject','withdraw'],true)){self::fail('Use the current pending preview.');}if($decision!=='withdraw'&&$b->created_by===$m->id){self::fail('A different administrator/CEO must apply or reject the preview.');}
   $after=[];if($decision==='apply'){
    if(!self::fresh($b,true)){self::fail();}$field=$b->slot.'_id';
    foreach($b->source['rows']as$row){$a=Apprentice::findOrFail($row['id']);$before=$a->only(AssignmentHistory::KEYS);$a->$field=$b->target_user_id;$a->version++;$a->save();AssignmentHistory::record($a,$m->id,$before);DB::table('audit_events')->insert(['user_id'=>$m->id,'apprentice_id'=>$a->id,'event'=>'staff_handover_applied','created_at'=>now()]);$after[]=['id'=>$a->id,'version'=>$a->version,'links'=>$a->only(AssignmentHistory::KEYS),'profile_hash'=>ReviewedImports::hash($a->getAttributes())];}
   }
   $b->state=['apply'=>'Applied','reject'=>'Rejected','withdraw'=>'Withdrawn'][$decision];$b->version++;$b->reviewed_by=$m->id;$b->reviewed_at=now()->setMicrosecond(0);$b->review_note=$note;$b->receipt=$decision==='apply'?['source_hash'=>$b->source_hash,'version'=>$b->version,'by'=>$m->id,'at'=>$b->reviewed_at->toIso8601String(),'after'=>$after]:null;$b->save();self::event($b,$m,'preview_'.$decision,$b->version,['note'=>$note,'after'=>$after]);
  },3);
 }
 public static function incomingScope(Batch $b,User $u,bool $lock=false):array {
  $rows=[];if($b->target_user_id!==$u->id||!self::recipientAllowed($u)||!self::eligible($u,$b->slot)||!self::applied($b)){return ['rows'=>[],'hash'=>null];}$ids=array_column($b->source['rows'],'id');$field=$b->slot.'_id';
  foreach(Apprentice::whereIn('id',$ids)->where($field,$u->id)->orderBy('id')->when($lock,fn($q)=>$q->lockForUpdate())->get()as$a){$rows[]=['id'=>$a->id,'reference'=>$a->reference,'name'=>trim($a->first_name.' '.$a->last_name),'version'=>$a->version];}
  return ['rows'=>$rows,'hash'=>ReviewedImports::hash(['batch'=>$b->id,'receipt'=>ReviewedImports::hash($b->receipt),'staff'=>self::staffSeal($u),'rows'=>$rows])];
 }
 public static function acknowledgement(Batch $b,User $u,array $scope):array {
  $a=DB::table('staff_handover_acknowledgements')->where('batch_id',$b->id)->where('user_id',$u->id)->first();$verified=false;try{$p=$a&&$a->proof?json_decode(Crypt::decryptString($a->proof),true,512,JSON_THROW_ON_ERROR):null;$verified=$p&&$p['batch']===$b->id&&$p['user']===$u->id&&$p['scope']===$a->scope_hash&&$p['version']===(int)$a->version&&$p['at']===$a->acknowledged_at;}catch(\Throwable){}return ['version'=>$a?(int)$a->version:0,'acknowledged_at'=>$verified&&$a&&$scope['rows']&&$scope['hash']&&hash_equals($a->scope_hash,$scope['hash'])?$a->acknowledged_at:null,'needs_recheck'=>$a&&$a->acknowledged_at&&(!$verified||!$scope['rows']||!$scope['hash']||!hash_equals($a->scope_hash,$scope['hash']))];
 }
 public static function acknowledge(User $actor,int $auth,Batch $record,int $version,string $hash,bool $confirmed):void {
  DB::transaction(function()use($actor,$auth,$record,$version,$hash,$confirmed):void {
   $u=User::whereKey($actor->id)->lockForUpdate()->firstOrFail();abort_unless(self::recipientAllowed($u)&&$u->auth_version===$auth,403);$b=Batch::whereKey($record->id)->lockForUpdate()->firstOrFail();abort_unless($b->target_user_id===$u->id,403);$scope=self::incomingScope($b,$u,true);
   if(!$scope['rows']||!$scope['hash']||!hash_equals($scope['hash'],$hash)){self::fail('Your current assignment scope changed. Reload the handover.');}$q=DB::table('staff_handover_acknowledgements')->where('batch_id',$b->id)->where('user_id',$u->id);$a=(clone$q)->lockForUpdate()->first();if(($a?(int)$a->version:0)!==$version){self::fail('Your acknowledgement changed. Reload before saving.');}
   if(DB::table('staff_handover_events')->where('actor_id',$u->id)->where('event','recipient_acknowledged')->where('created_at','>=',today())->count()>=100){self::fail('Please ask GTA for help before further handover confirmations today.');}
   $at=$confirmed?now()->setMicrosecond(0)->format('Y-m-d H:i:s'):null;$proof=Crypt::encryptString(json_encode(['batch'=>$b->id,'user'=>$u->id,'scope'=>$scope['hash'],'version'=>$version+1,'at'=>$at],JSON_THROW_ON_ERROR));$values=['scope_hash'=>$scope['hash'],'version'=>$version+1,'acknowledged_at'=>$at,'proof'=>$proof,'updated_at'=>now()];if($a){$q->update($values);}else{DB::table('staff_handover_acknowledgements')->insert([...$values,'batch_id'=>$b->id,'user_id'=>$u->id,'created_at'=>now()]);}self::event($b,$u,'recipient_acknowledged',$version+1,['confirmed'=>$confirmed,'scope_hash'=>$scope['hash'],'current_rows'=>count($scope['rows'])]);
  },3);
 }
 public static function workload(User $u):array {
  abort_unless(LearningAccess::manager($u),403);$counts=[];foreach(self::SLOTS as$slot=>$label){$counts[$slot]=Apprentice::whereNotNull($slot.'_id')->selectRaw($slot.'_id AS owner, COUNT(*) AS total')->groupBy($slot.'_id')->pluck('total','owner');}
  $rows=[];foreach(User::orderBy('name')->orderBy('id')->get()as$staff){$slots=[];$total=0;foreach(self::SLOTS as$slot=>$label){$n=(int)($counts[$slot][$staff->id]??0);$total+=$n;$slots[$slot]=['count'=>$n,'eligible'=>self::eligible($staff,$slot)];}if($total||in_array($staff->role,DirectoryCatalog::STAFF_ROLES,true)){$rows[]=['user'=>$staff,'slots'=>$slots,'total'=>$total];}}
  $unassigned=[];foreach(self::SLOTS as$slot=>$label){$unassigned[$slot]=Apprentice::whereNull($slot.'_id')->count();}return ['rows'=>$rows,'unassigned'=>$unassigned,'meaning'=>'Counts include all saved apprentice profiles, regardless of programme status. Multiple assignments count separately. These are not FTE, performance, capacity or a recommended workload.'];
 }
}
