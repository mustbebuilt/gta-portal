<?php
namespace App\Support;
use App\Models\{User,Apprentice,TutorGroup,CentreManagerGrant as Grant,CentreManagerAccessRequest as Request};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class CentreManagerAccess {
 public const AREAS=['training'=>'Training and marking status','attendance'=>'Attendance and recorded OTJ','reviews'=>'Review due references','functional_skills'=>'Maths and English status','coordination'=>'Source-linked staff follow-ups'];
 public static function administrator(User $u):User {$u=PortalActions::actor($u);abort_unless(LearningAccess::manager($u),403);return $u;}
 private static function targetSeal(User $u):array{return ['id'=>$u->id,'role'=>$u->role,'auth'=>$u->auth_version,'created_at'=>$u->created_at?->toIso8601String()];}
 public static function grantFields(Grant $g):array{return $g->only(['user_id','version','active','ceo_locked','updated_by'])+['expires_on'=>$g->expires_on->toDateString()];}
 public static function intactGrant(Grant $g):bool {try{return ReviewedImports::hash($g->details)===$g->details_hash&&$g->details['fields']===self::grantFields($g)&&array_keys($g->details['policy'])===['groups','areas'];}catch(\Throwable){return false;}}
 public static function policy(User $actor):?array {
  $u=User::whereKey($actor->id)->when(DB::transactionLevel()>0,fn($q)=>$q->lockForUpdate())->first();if(!$u||!$u->active||AccountLifecycle::blocked($u)){return null;}
  if(LearningAccess::manager($u)){return ['administrator'=>true,'groups'=>null,'areas'=>array_keys(self::AREAS),'grant_version'=>null];}
  $g=Grant::whereKey($u->id)->when(DB::transactionLevel()>0,fn($q)=>$q->lockForUpdate())->first();try{if(!$g||!self::intactGrant($g)||!$g->active||$g->expires_on->lt(today())||$g->details['target']!==self::targetSeal($u)){return null;}$p=$g->details['policy'];
   foreach($g->details['group_identity']as$group){$current=TutorGroup::whereKey($group['id'])->when(DB::transactionLevel()>0,fn($q)=>$q->lockForUpdate())->first();if(!$current||$current->only(['id','reference','active'])!==$group){return null;}}
   return ['administrator'=>false,...$p,'grant_version'=>$g->version];
  }catch(\Throwable){return null;}
 }
 public static function workspace(User $u):bool{return self::policy($u)!==null;}
 public static function allows(User $u,Apprentice $a,string $area):bool {$p=self::policy($u);$a=Apprentice::find($a->id);return $a&&$p&&in_array($area,$p['areas'],true)&&($p['administrator']||in_array($a->tutor_group_id,$p['groups'],true));}
 public static function people(User $u){$p=self::policy($u);abort_unless($p,403);return Apprentice::query()->when(!$p['administrator'],fn($q)=>$q->whereIn('tutor_group_id',$p['groups']));}
 public static function reference(string $v):bool{return mb_strlen(trim($v))>=5&&mb_strlen($v)<=1000;}
 private static function requestFields(Request $r):array{return $r->only(['id','request_token','created_by','target_user_id','state','version','source_hash','details_hash']);}
 private static function binding(Request $r):array{return $r->only(['id','request_token','created_by','target_user_id','source_hash']);}
 public static function intact(Request $r):bool {
  try{if(ReviewedImports::hash($r->details)!==$r->details_hash||$r->details['fields']!==self::binding($r)){return false;}
   if($r->state==='Draft'){return $r->version===1&&$r->approval===null&&$r->applied===null;}
   if(in_array($r->state,['Approved','Rejected'],true)){return $r->version===2&&$r->approval['fields']===self::requestFields($r)&&$r->approval['accepted']===($r->state==='Approved')&&$r->applied===null;}
   return $r->state==='Applied'&&$r->version===3&&$r->approval['fields']===array_replace(self::requestFields($r),['state'=>'Approved','version'=>2])&&$r->approval['accepted']===true&&$r->applied['fields']===self::requestFields($r)&&$r->applied['approval_hash']===ReviewedImports::hash($r->approval);
  }catch(\Throwable){return false;}
 }
 public static function preview(User $actor,User $target,array $groups,array $areas,string $expires,bool $locked,bool $lock=false):array {
  $u=self::administrator($actor);$t=User::whereKey($target->id)->when($lock,fn($q)=>$q->lockForUpdate())->firstOrFail();
  if(!$t->active||AccountLifecycle::blocked($t)||!in_array($t->role,DirectoryCatalog::STAFF_ROLES,true)||$t->id===$u->id){CentrePlanning::fail('Choose an active retained staff account; administrator and CEO powers remain separate.');}
  if(!$groups||count($groups)>20||count(array_unique($groups))!==count($groups)||array_filter($groups,fn($id)=>!is_int($id)||$id<1)||!$areas||count(array_unique($areas))!==count($areas)||array_diff($areas,array_keys(self::AREAS))||!array_diff($areas,['coordination'])){CentrePlanning::fail('Choose1–20 exact groups and at least one operational area; follow-ups also need an operational area.');}
  sort($groups,SORT_NUMERIC);sort($areas,SORT_STRING);validator(['expires'=>$expires],['expires'=>'required|date_format:Y-m-d|after_or_equal:today|before_or_equal:'.today()->addDays(365)->toDateString()])->validate();
  $prior=Grant::whereKey($t->id)->when($lock,fn($q)=>$q->lockForUpdate())->first();if($prior&&!self::intactGrant($prior)){CentrePlanning::fail('Existing access proof needs investigation; it cannot be silently replaced.');}
  if($u->role!=='ceo'&&($locked||$prior?->ceo_locked)){CentrePlanning::fail('Only the CEO can create or change CEO-protected Centre Manager access.');}
  $rows=TutorGroup::whereIn('id',$groups)->orderBy('id')->when($lock,fn($q)=>$q->lockForUpdate())->get();if($rows->count()!==count($groups)||$rows->contains(fn($g)=>!$g->active)){CentrePlanning::fail('Use current active tutor groups.');}
  $scope=['target'=>self::targetSeal($t),'groups'=>$rows->map->getAttributes()->all(),'prior'=>$prior?->getAttributes(),'policy'=>['groups'=>$groups,'areas'=>$areas],'expires'=>$expires,'locked'=>$locked];
  return ['target'=>$t->only(['id','name','role']),'groups'=>$rows->map(fn($g)=>$g->only(['id','reference','name','active']))->all(),'areas'=>$areas,'expires'=>$expires,'ceo_locked'=>$locked,'prior_version'=>$prior?->version??0,'source_hash'=>ReviewedImports::hash($scope),'policy'=>$scope['policy'],'group_identity'=>$rows->map(fn($g)=>$g->only(['id','reference','active']))->all(),'target_seal'=>self::targetSeal($t)];
 }
 public static function prepare(User $actor,int $auth,User $target,array $groups,array $areas,string $expires,bool $locked,string $expected,string $token,string $reference):Request {
  if(!Str::isUuid($token)||!self::reference($reference)){CentrePlanning::fail('Use a fresh request and clear access reason.');}
  return DB::transaction(function()use($actor,$auth,$target,$groups,$areas,$expires,$locked,$expected,$token,$reference){$u=PortalOperations::actor($actor,$auth,true);$v=self::preview($u,$target,$groups,$areas,$expires,$locked,true);
   if($old=Request::where('request_token',$token)->lockForUpdate()->first()){if(self::intact($old)&&$old->created_by===$u->id&&$old->details['creator_auth']===$auth&&$old->target_user_id===$target->id&&$old->source_hash===$expected&&$old->details['reference']===$reference&&$old->details['preview']['policy']===$v['policy']&&$old->details['preview']['expires']===$expires&&$old->details['preview']['ceo_locked']===$locked){return $old;}CentrePlanning::fail('Request already belongs to different access work.');}
   if($v['source_hash']!==$expected){CentrePlanning::fail('Target, groups or existing permissions changed. Check a fresh preview.');}
   $r=new Request(['id'=>(string)Str::uuid(),'request_token'=>$token,'created_by'=>$u->id,'target_user_id'=>$target->id,'state'=>'Draft','version'=>1,'source_hash'=>$expected]);$r->details=['fields'=>self::binding($r),'creator_auth'=>$auth,'reference'=>$reference,'preview'=>$v];$r->details_hash=ReviewedImports::hash($r->details);$r->save();PortalOperations::event($u,'centre_access',$r->id,'centre_access_prepared',['target'=>$target->id,'source_hash'=>$expected,'no_access_granted'=>true]);return $r;
  },3);
 }
 public static function review(User $actor,int $auth,Request $record,int $version,bool $accept,string $reference,bool $ceoOverride=false):void {
  if(!self::reference($reference)){CentrePlanning::fail('Record the reviewed access decision.');}
  DB::transaction(function()use($actor,$auth,$record,$version,$accept,$reference,$ceoOverride){$u=PortalOperations::actor($actor,$auth,true);$r=Request::whereKey($record->id)->lockForUpdate()->firstOrFail();
   if(!self::intact($r)||$r->state!=='Draft'||$r->version!==$version){CentrePlanning::fail('Access request changed or needs investigation.');}
   if($u->id===$r->created_by&&!($u->role==='ceo'&&$ceoOverride)){CentrePlanning::fail('Use an independent manager review, or the explicit audited CEO self-review override.');}
   if($accept){$maker=User::find($r->created_by);if(!$maker||!$maker->active||AccountLifecycle::blocked($maker)||!LearningAccess::manager($maker)||$maker->auth_version!==$r->details['creator_auth']){CentrePlanning::fail('Creator access changed. Prepare a fresh request.');}$p=$r->details['preview'];$v=self::preview($maker,User::findOrFail($r->target_user_id),$p['policy']['groups'],$p['areas'],$p['expires'],$p['ceo_locked'],true);if($v['source_hash']!==$r->source_hash){CentrePlanning::fail('Access source changed before review.');}}
   $r->state=$accept?'Approved':'Rejected';$r->version++;$r->approval=['fields'=>self::requestFields($r),'accepted'=>$accept,'by'=>$u->id,'auth'=>$auth,'reference'=>$reference,'ceo_self_override'=>$u->id===$r->created_by&&$ceoOverride,'at'=>now()->toIso8601String()];$r->save();PortalOperations::event($u,'centre_access',$r->id,'centre_access_reviewed',['accepted'=>$accept,'ceo_self_override'=>$r->approval['ceo_self_override']]);
  },3);
 }
 public static function apply(User $actor,int $auth,Request $record,int $version):Grant {
  return DB::transaction(function()use($actor,$auth,$record,$version){$u=PortalOperations::actor($actor,$auth,true);$r=Request::whereKey($record->id)->lockForUpdate()->firstOrFail();if(!self::intact($r)||$version!==2){CentrePlanning::fail('Use the exact reviewed request.');}
   if($r->state==='Applied'){$g=Grant::whereKey($r->target_user_id)->lockForUpdate()->firstOrFail();if($r->applied['by']!==$u->id||$r->applied['auth']!==$auth||$r->applied['grant_hash']!==$g->details_hash||!self::intactGrant($g)){CentrePlanning::fail('The previous outcome or actor changed; inspect the recorded access history.');}return $g;}
   if($r->state!=='Approved'||$r->version!==$version){CentrePlanning::fail('Approve this request before activation.');}
   $maker=User::whereKey($r->created_by)->lockForUpdate()->first();$checker=User::whereKey($r->approval['by'])->lockForUpdate()->first();foreach([[$maker,$r->details['creator_auth']],[$checker,$r->approval['auth']]]as[$person,$v]){if(!$person||!$person->active||AccountLifecycle::blocked($person)||!LearningAccess::manager($person)||$person->auth_version!==$v){CentrePlanning::fail('Creator/reviewer authority changed; prepare a fresh request.');}}
   $p=$r->details['preview'];$target=User::whereKey($r->target_user_id)->lockForUpdate()->firstOrFail();$now=self::preview($maker,$target,$p['policy']['groups'],$p['areas'],$p['expires'],$p['ceo_locked'],true);if($now['source_hash']!==$r->source_hash){CentrePlanning::fail('Access source changed since review.');}
   // A subordinate applier cannot activate a CEO-protected change on somebody else's behalf.
   if($u->role!=='ceo'&&($p['ceo_locked']||Grant::find($target->id)?->ceo_locked)){CentrePlanning::fail('Only the CEO can activate CEO-protected access.');}
   $g=Grant::whereKey($target->id)->lockForUpdate()->first()??new Grant(['user_id'=>$target->id,'version'=>0]);$history=$g->exists?[...($g->details['history']??[]),['fields'=>self::grantFields($g),'policy'=>$g->details['policy'],'target'=>$g->details['target']]]:[];$g->version++;$g->active=true;$g->expires_on=$p['expires'];$g->ceo_locked=$p['ceo_locked'];$g->updated_by=$u->id;$g->details=['fields'=>self::grantFields($g),'target'=>$now['target_seal'],'policy'=>$now['policy'],'group_identity'=>$now['group_identity'],'request'=>$r->id,'history'=>$history];$g->details_hash=ReviewedImports::hash($g->details);$g->save();
   $r->state='Applied';$r->version++;$r->applied=['fields'=>self::requestFields($r),'by'=>$u->id,'auth'=>$auth,'grant_hash'=>$g->details_hash,'approval_hash'=>ReviewedImports::hash($r->approval),'at'=>now()->toIso8601String()];$r->save();PortalOperations::event($u,'centre_access',$r->id,'centre_access_activated',['target'=>$target->id,'grant_version'=>$g->version,'grant_hash'=>$g->details_hash,'canonical_role_changed'=>false]);return $g;
  },3);
 }
 public static function revoke(User $actor,int $auth,User $target,int $version,string $reference):void {
  if(!self::reference($reference)){CentrePlanning::fail('Record why this access is being revoked.');}
  DB::transaction(function()use($actor,$auth,$target,$version,$reference){$u=PortalOperations::actor($actor,$auth,true);$g=Grant::whereKey($target->id)->lockForUpdate()->firstOrFail();if(!self::intactGrant($g)||$g->version!==$version||!$g->active){CentrePlanning::fail('Access changed or already inactive; reload.');}if($g->ceo_locked&&$u->role!=='ceo'){CentrePlanning::fail('Only the CEO can revoke CEO-protected access.');}$d=$g->details;$d['history'][]=['fields'=>self::grantFields($g),'policy'=>$d['policy'],'by'=>$u->id,'reference'=>$reference,'at'=>now()->toIso8601String()];$g->version++;$g->active=false;$g->updated_by=$u->id;$d['fields']=self::grantFields($g);$g->details=$d;$g->details_hash=ReviewedImports::hash($d);$g->save();PortalOperations::event($u,'centre_access',(string)$target->id,'centre_access_revoked',['version'=>$g->version,'canonical_role_changed'=>false]);},3);
 }
}
