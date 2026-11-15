<?php
namespace App\Support;
use App\Models\{Apprentice,EmployerApprenticeGrant,SupportPlan,User,WorkplaceSupportShare as Share};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WorkplaceSupportSharing {
 public const PERMISSION='I have read this exact summary and allow it to be shown to this named person until the stated expiry or my earlier withdrawal.';
 public const STATEMENT_VERSION='workplace-support-permission-v1';
 private static function fail(string $message='This support summary changed or its source/authority needs rechecking. Start a new checked proposal.'):never {throw ValidationException::withMessages(['sharing'=>$message]);}
 public static function staff(User $u,Apprentice $a):bool {return SupportAccess::team($u,$a);}
 public static function learner(User $u,Share $s,Apprentice $a):bool {
  return $u->active&&$u->role==='apprentice'&&$u->email_verified_at&&$u->id===$s->learner_id&&$a->user_id===$u->id&&Apprentice::where('user_id',$u->id)->count()===1;
 }
 private static function actor(User $u,int $auth):User {
  $u=User::whereKey($u->id)->lockForUpdate()->firstOrFail();abort_unless($u->active&&$u->auth_version===$auth,403);return $u;
 }
 private static function event(Share $s,User $u,string $event):void {DB::table('workplace_support_share_events')->insert(['share_id'=>$s->id,'actor_id'=>$u->id,'event'=>$event,'share_version'=>$s->version,'created_at'=>now()]);}
 private static function identity(User $u):array {return ['id'=>$u->id,'name'=>$u->name,'email'=>$u->email,'auth_version'=>$u->auth_version];}
 public static function source(Apprentice $a,SupportPlan $p,EmployerApprenticeGrant $g):?array {
  $learner=$a->user_id?User::whereKey($a->user_id)->when(DB::transactionLevel()>0,fn($q)=>$q->lockForUpdate())->first():null;
  $recipient=User::whereKey($g->user_id)->when(DB::transactionLevel()>0,fn($q)=>$q->lockForUpdate())->first();
  if(!$learner||!$learner->active||$learner->role!=='apprentice'||!$learner->email_verified_at||strtolower(trim($a->email??''))!==strtolower(trim($learner->email))||Apprentice::where('user_id',$learner->id)->count()!==1||User::whereRaw('LOWER(email) = ?',[strtolower($learner->email)])->count()!==1||!$recipient||$p->apprentice_id!==$a->id||$p->status!=='Active'||!$p->active_plan||!EmployerActions::valid($g,$a)){return null;}
  return ['profile'=>['id'=>$a->id,'reference'=>$a->reference,'first_name'=>$a->first_name,'last_name'=>$a->last_name,'user_id'=>$a->user_id,'employer_id'=>$a->employer_id],
   'learner'=>self::identity($learner),'recipient'=>self::identity($recipient),
   'employer'=>['id'=>$g->employer_id,'name'=>$a->employerRecord?->name,'version'=>$g->employer_version],
   'plan'=>['id'=>$p->id,'active_hash'=>ReviewedImports::hash($p->active_plan)],
   'grant'=>['id'=>$g->id,'version'=>$g->version,'identity_hash'=>$g->identity_hash]];
 }
 public static function current(Share $s):bool {
  try {
   $a=Apprentice::find($s->apprentice_id);$p=SupportPlan::find($s->plan_id);$g=EmployerApprenticeGrant::find($s->grant_id);
   if(!$a||!$p||!$g||!$s->expires_at||now()->greaterThanOrEqualTo($s->expires_at)){return false;}
   $source=self::source($a,$p,$g);
   return $source&&$s->learner_id===$source['learner']['id']&&$s->recipient_id===$source['recipient']['id']
    &&ReviewedImports::hash($source)===$s->source_hash&&ReviewedImports::hash($s->source)===$s->source_hash
    &&ReviewedImports::hash($s->payload)===$s->payload_hash
    &&($s->payload['expires_at']??null)===$s->expires_at->toIso8601String();
  }catch(\Throwable){return false;}
 }
 public static function permissionValid(Share $s):bool {
  try {$p=$s->permission;return $s->checked_at&&$s->checked_by&&$s->decided_at&&$p&&($p['decision']??null)==='allow'&&($p['statement_version']??null)===self::STATEMENT_VERSION&&($p['statement']??null)===self::PERMISSION&&($p['checked_by']??null)===$s->checked_by&&($p['checked_at']??null)===$s->checked_at->toIso8601String()&&($p['learner_id']??null)===$s->learner_id&&($p['source_hash']??null)===$s->source_hash&&($p['payload_hash']??null)===$s->payload_hash&&($p['recipient_id']??null)===$s->recipient_id&&($p['expires_at']??null)===$s->expires_at->toIso8601String()&&($p['decided_at']??null)===$s->decided_at->toIso8601String();}catch(\Throwable){return false;}
 }
 public static function employerVisible(User $u,Share $s):bool {
  return $u->active&&$u->role==='employer'&&$u->email_verified_at&&$u->id===$s->recipient_id&&$s->state==='Shared'&&!$s->withdrawn_at&&self::current($s)&&self::permissionValid($s);
 }
 private static function locked(Share $s):array {
  // All this module's mutations serialize on the apprentice before source/authority rechecks.
  $a=Apprentice::whereKey($s->apprentice_id)->lockForUpdate()->firstOrFail();
  $s=Share::whereKey($s->id)->lockForUpdate()->firstOrFail();
  SupportPlan::whereKey($s->plan_id)->lockForUpdate()->firstOrFail();EmployerApprenticeGrant::whereKey($s->grant_id)->lockForUpdate()->firstOrFail();return [$a,$s];
 }
 public static function prepare(User $actor,int $auth,Apprentice $profile,array $data):Share {
  return DB::transaction(function()use($actor,$auth,$profile,$data):Share {
   $a=Apprentice::whereKey($profile->id)->lockForUpdate()->firstOrFail();$u=self::actor($actor,$auth);abort_unless(SupportAccess::writer($u,$a),403);
   $p=SupportPlan::whereKey($data['plan_id'])->where('apprentice_id',$a->id)->lockForUpdate()->firstOrFail();$g=EmployerApprenticeGrant::whereKey($data['grant_id'])->where('apprentice_id',$a->id)->lockForUpdate()->firstOrFail();
   if($p->version!==(int)$data['plan_version']||$g->version!==(int)$data['grant_version']){self::fail('The plan or named employer access changed. Reload before preparing.');}
   $source=self::source($a,$p,$g);if(!$source){self::fail('Use an active approved support plan, the apprentice’s own confirmed account and current verified named employer authority.');}
   $expires=\Carbon\Carbon::parse($data['expires_on'],config('app.timezone'))->endOfDay()->setMicrosecond(0);
   if($expires->lessThanOrEqualTo(now())||$expires->greaterThan(today()->addDays(30)->endOfDay())){self::fail('Choose an expiry from today to 30 days ahead.');}
   $payload=['title'=>trim($data['title']),'adjustments'=>trim($data['adjustments']),'employer_actions'=>trim($data['employer_actions']),'expires_at'=>$expires->toIso8601String()];
   $old=Share::where('request_token',$data['request_token'])->first();
   if($old){if($old->prepared_by===$u->id&&$old->apprentice_id===$a->id&&$old->source_hash===ReviewedImports::hash($source)&&$old->payload_hash===ReviewedImports::hash($payload)){return $old;}self::fail('This request was already used. Reload before making a different proposal.');}
   $s=new Share(['request_token'=>$data['request_token'],'apprentice_id'=>$a->id,'plan_id'=>$p->id,'grant_id'=>$g->id,'learner_id'=>$source['learner']['id'],'recipient_id'=>$source['recipient']['id'],'prepared_by'=>$u->id,'state'=>'Draft','version'=>1,'payload'=>$payload,'payload_hash'=>ReviewedImports::hash($payload),'source'=>$source,'source_hash'=>ReviewedImports::hash($source),'expires_at'=>$expires]);$s->id=(string)Str::uuid();$s->save();self::event($s,$u,'draft_prepared');return $s;
  });
 }
 public static function requestPermission(User $actor,int $auth,Share $record,int $version):void {
  DB::transaction(function()use($actor,$auth,$record,$version):void {
   [$a,$s]=self::locked($record);$u=self::actor($actor,$auth);abort_unless(SupportAccess::approver($u,$a),403);
   if($s->version!==$version||$s->state!=='Draft'||!self::current($s)){self::fail();}
   $s->state='Awaiting apprentice';$s->checked_by=$u->id;$s->checked_at=now()->setMicrosecond(0);$s->version++;$s->save();self::event($s,$u,'checked_and_requested');
  });
 }
 public static function decide(User $actor,int $auth,Share $record,int $version,string $decision):void {
  abort_unless(in_array($decision,['allow','decline'],true),422);
  DB::transaction(function()use($actor,$auth,$record,$version,$decision):void {
   [$a,$s]=self::locked($record);$u=self::actor($actor,$auth);abort_unless(self::learner($u,$s,$a),403);
   if($s->version!==$version||$s->state!=='Awaiting apprentice'||($decision==='allow'&&(!$s->checked_by||!$s->checked_at||!self::current($s)))){self::fail();}
   $s->decided_at=now()->setMicrosecond(0);$s->permission=['decision'=>$decision,'statement_version'=>self::STATEMENT_VERSION,'statement'=>$decision==='allow'?self::PERMISSION:'Do not share','checked_by'=>$s->checked_by,'checked_at'=>$s->checked_at?->toIso8601String(),'learner_id'=>$u->id,'source_hash'=>$s->source_hash,'payload_hash'=>$s->payload_hash,'recipient_id'=>$s->recipient_id,'expires_at'=>$s->expires_at->toIso8601String(),'decided_at'=>$s->decided_at->toIso8601String()];$s->state=$decision==='allow'?'Shared':'Declined';$s->version++;$s->save();self::event($s,$u,$decision==='allow'?'apprentice_allowed':'apprentice_declined');
  });
 }
 public static function withdraw(User $actor,int $auth,Share $record,int $version):void {
  DB::transaction(function()use($actor,$auth,$record,$version):void {
   [$a,$s]=self::locked($record);$u=self::actor($actor,$auth);
   $own=self::learner($u,$s,$a);abort_unless($own||SupportAccess::writer($u,$a),403);
   if($s->version!==$version||!in_array($s->state,$own?['Awaiting apprentice','Shared']:['Draft','Awaiting apprentice','Shared'],true)){self::fail('Summary changed or already closed. Reload first.');}
   $s->state='Withdrawn';$s->withdrawn_at=now();$s->version++;$s->save();self::event($s,$u,$own?'apprentice_withdrew':'staff_withdrew');
  });
 }
 public static function acknowledge(User $actor,int $auth,Share $record,int $version):void {
  DB::transaction(function()use($actor,$auth,$record,$version):void {
   [, $s]=self::locked($record);$u=self::actor($actor,$auth);abort_unless(self::employerVisible($u,$s),404);
   if($s->version!==$version||$s->acknowledged_at){self::fail('Summary changed or already acknowledged. Reload first.');}
   $s->acknowledged_at=now();$s->version++;$s->save();self::event($s,$u,'employer_acknowledged');
  });
 }
}
