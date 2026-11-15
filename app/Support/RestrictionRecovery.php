<?php
namespace App\Support;
use App\Models\{User,AccountLifecycleState as State,AccountLifecycleProposal as Proposal,RestrictionRecoveryRun as Run};
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;
use Carbon\Carbon;

/** Reapply missing login restrictions after an independently restored database. Never reactivate or purge. */
class RestrictionRecovery
{
 private static function decode(string $sealed):array
 {
  try{
   if(strlen($sealed)>1_048_576||base64_encode(base64_decode($sealed,true)?:'')!==$sealed){throw new \RuntimeException();}
   $v=json_decode(Crypt::decryptString($sealed),true,32,JSON_THROW_ON_ERROR);
   if(($v['format']??null)!=='gta-account-restrictions-43'||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:\d{2}|Z)$/D',$v['created_at']??'')||Carbon::parse($v['created_at'])->gt(now()->addMinutes(5))||!is_array($v['rows']??null)||!array_is_list($v['rows'])||count($v['rows'])>500){throw new \RuntimeException();}
   $ids=[];foreach($v['rows']as$row){if(!is_int($row['user_id']??null)||$row['user_id']<1||isset($ids[$row['user_id']])||!preg_match('/^[a-f0-9]{64}$/D',$row['identity_hash']??'')||!is_int($row['version']??null)||$row['version']<1||!in_array($row['status']??null,['Restricted','Archived'],true)){throw new \RuntimeException();}$ids[$row['user_id']]=true;}
   return $v;
  }catch(\Throwable){CentrePlanning::fail('The retained-key restriction file could not be authenticated or has an unsupported shape. Use a valid ledger of up to500 accounts; do not match identities by name.');}
 }
 public static function preview(User $actor,string $sealed):array
 {
  $u=PortalActions::actor($actor);abort_unless(LearningAccess::manager($u),403);$v=self::decode($sealed);$rows=[];$proof=[];
  foreach($v['rows']as$r){$t=User::find($r['user_id']);$s=$t?State::find($t->id):null;$match=$t&&hash_equals($r['identity_hash'],ReviewedImports::hash([$t->email,$t->created_at?->toIso8601String()]));$kind='identity';
   if($match){
    if($t->id===$u->id||$t->role==='ceo'){$kind='protected';}
    elseif($s&&!AccountLifecycle::stateIntact($s)){$kind='proof';}
    elseif(!$t->active||AccountLifecycle::blocked($t)){$kind='held';}
    elseif($s&&$s->status==='Active'&&$s->updated_at->gt(Carbon::parse($v['created_at']))) {$kind='later-decision';}
    else{$kind='missing';}
   }
   $rows[]=['user_id'=>$r['user_id'],'name'=>$match?$t->name:'Identity unavailable','role'=>$match?PortalLanguage::role($t->role):'Not matched','recorded_status'=>$r['status'],'position'=>$kind,'eligible'=>$kind==='missing'];
   $proof[]=['ledger'=>$r,'user'=>$t?->getAttributes(),'state'=>$s?->getAttributes(),'position'=>$kind,'source_hash'=>$match?AccountLifecycle::source($t)['hash']:null];
  }
  return ['captured_at'=>$v['created_at'],'rows'=>$rows,'hash'=>ReviewedImports::hash(['sealed_hash'=>hash('sha256',$sealed),'actor'=>$u->id,'rows'=>$proof]),'eligible'=>count(array_filter($rows,fn($x)=>$x['eligible'])),'writes_performed'=>false,'automatic_reactivation'=>false];
 }
 public static function intact(Run $r):bool
 {
  try{
   $d=$r->details;if(ReviewedImports::hash($d)!==$r->details_hash||$d['format']!=='restriction-recovery46'||$d['run']!==$r->id||$d['request']!==$r->request_token||$d['maker']!==$r->created_by||$d['source_hash']!==$r->source_hash){return false;}
   return match($r->state){'Draft'=>$r->version===1&&!$r->approval&&!$r->applied,'Approved','Rejected'=>$r->version===2&&$r->approval['state']===$r->state&&$r->approval['details_hash']===$r->details_hash&&!$r->applied,'Applied'=>$r->version===3&&$r->approval['state']==='Approved'&&$r->applied['approval_hash']===ReviewedImports::hash($r->approval),default=>false};
  }catch(\Throwable){return false;}
 }
 public static function prepare(User $actor,int $auth,string $sealed,string $expected,array $selected,string $token,string $reviewOn,string $retention,string $handover,string $reason):Run
 {
  return DB::transaction(function()use($actor,$auth,$sealed,$expected,$selected,$token,$reviewOn,$retention,$handover,$reason){
   User::orderBy('id')->lockForUpdate()->get(['id']);$u=PortalOperations::actor($actor,$auth,true);
   $selected=array_map('intval',$selected);sort($selected);if(!$selected||count($selected)>25||count(array_unique($selected))!==count($selected)||!Str::isUuid($token)||!PortalOperations::reference($retention)||!PortalOperations::reference($handover)||!PortalOperations::reference($reason)){CentrePlanning::fail('Select1–25 distinct missing restrictions and provide reviewed retention, continuity and reason references.');}
   validator(['review_on'=>$reviewOn],['review_on'=>'required|date_format:Y-m-d|after:today'])->validate();
   $binding=['sealed_hash'=>hash('sha256',$sealed),'expected'=>$expected,'selected'=>$selected,'review_on'=>$reviewOn,'retention'=>$retention,'handover'=>$handover,'reason'=>$reason];
   if($old=Run::where('request_token',$token)->lockForUpdate()->first()){if(self::intact($old)&&$old->created_by===$u->id&&$old->details['maker_auth']===$auth&&$old->details['binding']===$binding){return $old;}CentrePlanning::fail('Request already used for another recovery decision.');}
   $preview=self::preview($u,$sealed);if($preview['hash']!==$expected){CentrePlanning::fail('Ledger identity, account, assignment or current decision changed. Review a fresh preview.');}
   $eligible=collect($preview['rows'])->where('eligible',true)->keyBy('user_id');$id=(string)Str::uuid();$proposals=[];
   foreach($selected as$target){$row=$eligible->get($target);if(!$row){CentrePlanning::fail('Only currently matched, unprotected accounts with a missing restriction can be selected.');}$t=User::findOrFail($target);$action=$row['recorded_status']==='Archived'?'Archive':'Restrict';$p=AccountLifecycle::prepare($u,$auth,$t,(string)Str::uuid(),$action,AccountLifecycle::source($t)['hash'],$reviewOn,$retention,$handover,$reason);$proposals[]=['id'=>$p->id,'target'=>$target,'name'=>$t->name,'action'=>$action,'source_hash'=>$p->source_hash];}
   $d=['format'=>'restriction-recovery46','run'=>$id,'request'=>$token,'maker'=>$u->id,'maker_auth'=>$auth,'source_hash'=>$expected,'binding'=>$binding,'ledger_created_at'=>$preview['captured_at'],'proposals'=>$proposals];
   $r=Run::create(['id'=>$id,'request_token'=>$token,'created_by'=>$u->id,'state'=>'Draft','version'=>1,'source_hash'=>$expected,'details'=>$d,'details_hash'=>ReviewedImports::hash($d)]);PortalOperations::event($u,'restriction_recovery',$id,'recovery_prepared',['count'=>count($proposals),'details_hash'=>$r->details_hash,'no_login_changes'=>true]);return $r;
  },3);
 }
 public static function review(User $actor,int $auth,Run $record,int $version,bool $accept,string $reference,bool $override=false):void
 {
  DB::transaction(function()use($actor,$auth,$record,$version,$accept,$reference,$override){User::orderBy('id')->lockForUpdate()->get(['id']);$u=PortalOperations::actor($actor,$auth,true);$r=Run::whereKey($record->id)->lockForUpdate()->firstOrFail();$d=$r->details;
   abort_if($u->id===$r->created_by&&!($u->role==='ceo'&&$override)||$override&&$u->role!=='ceo'||in_array($u->id,array_column($d['proposals'],'target'),true),403);
   if(!self::intact($r)||$r->state!=='Draft'||$r->version!==$version||!PortalOperations::reference($reference)){CentrePlanning::fail('Review the current intact recovery request.');}
   foreach($d['proposals']as$row){$p=Proposal::findOrFail($row['id']);if($p->created_by!==$r->created_by||$p->target_id!==$row['target']||$p->source_hash!==$row['source_hash']){CentrePlanning::fail('A linked account proposal changed.');}AccountLifecycle::review($u,$auth,$p,1,$accept,$reference,$override);}
   $r->version++;$r->state=$accept?'Approved':'Rejected';$r->approval=['state'=>$r->state,'details_hash'=>$r->details_hash,'by'=>$u->id,'auth'=>$auth,'reference'=>$reference,'ceo_override'=>$override,'at'=>now()->toIso8601String()];$r->save();PortalOperations::event($u,'restriction_recovery',$r->id,'recovery_reviewed',['approval'=>$r->approval]);
  },3);
 }
 public static function apply(User $actor,int $auth,Run $record,int $version):void
 {
  DB::transaction(function()use($actor,$auth,$record,$version){User::orderBy('id')->lockForUpdate()->get(['id']);$u=PortalOperations::actor($actor,$auth,true);$r=Run::whereKey($record->id)->lockForUpdate()->firstOrFail();
   if(self::intact($r)&&$r->state==='Applied'&&$version===2&&$r->applied['by']===$u->id&&$r->applied['auth']===$auth){return;}
   $maker=User::find($r->created_by);$checker=User::find($r->approval['by']??0);
   if(!self::intact($r)||$r->state!=='Approved'||$r->version!==$version||!$maker||!$checker||!LearningAccess::manager($maker)||!LearningAccess::manager($checker)||AccountLifecycle::blocked($maker)||AccountLifecycle::blocked($checker)||$maker->auth_version!==$r->details['maker_auth']||$checker->auth_version!==$r->approval['auth']){CentrePlanning::fail('Recovery request or approving authority changed. Prepare a fresh reviewed request.');}
   abort_if(in_array($u->id,array_column($r->details['proposals'],'target'),true),403);
   foreach($r->details['proposals']as$row){$p=Proposal::whereKey($row['id'])->lockForUpdate()->firstOrFail();if($p->created_by!==$r->created_by||$p->target_id!==$row['target']||$p->source_hash!==$row['source_hash']||($p->approval['by']??null)!==$checker->id){CentrePlanning::fail('A linked account approval changed.');}AccountLifecycle::apply($u,$auth,$p,2);}
   $r->state='Applied';$r->version++;$r->applied=['by'=>$u->id,'auth'=>$auth,'at'=>now()->toIso8601String(),'approval_hash'=>ReviewedImports::hash($r->approval),'restricted_accounts'=>count($r->details['proposals'])];$r->save();PortalOperations::event($u,'restriction_recovery',$r->id,'recovery_applied',['receipt'=>$r->applied,'automatic_reactivation'=>false,'evidence_deleted'=>false]);
  },3);
 }
}
