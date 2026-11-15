<?php
namespace App\Support;
use App\Models\{User,Apprentice,Employer,EmployerApprenticeGrant as Grant};
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Validation\ValidationException;
class EmployerSetup {
 public const ITEMS=[
  'people-v1'=>['Check the apprentices I support','Check the names and workplace shown here. Ask GTA if someone is missing or incorrect.'],
  'actions-v1'=>['Find my actions','Use My employer actions to respond to requests published by GTA. A checklist does not confirm training or finish an action.'],
  'reviews-v1'=>['Understand review signing','Open Review signing for a review shared with you. Read its exact current version before deciding whether to sign.'],
  'support-v1'=>['Understand workplace support sharing','Only practical summaries explicitly shared with you are available. This does not give access to private support plans.'],
 ];
 private static function fail(string $m):never {throw ValidationException::withMessages(['employer_setup'=>$m]);}
 public static function invitation(User $u):array {
  $t=DB::table('account_onboarding_tokens')->where('user_id',$u->id)->where('purpose','employer_invite')->first();
  if(!$t){return ['state'=>'No invitation outstanding','stamp'=>null];}
  $issuer=$t->issuer_id?User::find($t->issuer_id):null;
  $current=(int)$t->auth_version===$u->auth_version&&$issuer&&LearningAccess::manager($issuer)&&$issuer->auth_version===(int)$t->issuer_auth_version;
  return ['state'=>now()->greaterThanOrEqualTo($t->expires_at)?'Expired':(!$current?'Account or issuer changed':(!AccountOnboarding::eligible($u,'employer_invite')?'Held by current account/mail controls':'Link prepared; delivery not guaranteed')),'stamp'=>hash_hmac('sha256',ReviewedImports::hash((array)$t),(string)config('app.key')),'created_at'=>$t->created_at,'expires_at'=>$t->expires_at];
 }
 public static function reasons(Grant $g,?Apprentice $a,User $u):array {
  $e=Employer::find($g->employer_id);$why=[];
  if(!$g->active){$why[]='Access revoked';}if(!$u->active||$u->role!=='employer'){$why[]='Employer account inactive or role changed';}if(!$u->email_verified_at){$why[]='Mailbox unconfirmed';}
  if(User::whereRaw('LOWER(email) = ?',[strtolower(trim($u->email))])->count()!==1){$why[]='Account email ambiguous';}
  if(!$a||$a->employer_id!==$g->employer_id){$why[]='Apprentice workplace changed or unavailable';}
  if(!$e?->active){$why[]='Employer inactive or unavailable';}elseif($e->record_version!==$g->employer_version){$why[]='Employer record changed';}
  if(!hash_equals($g->identity_hash,EmployerActions::identity($u))){$why[]='Contact identity changed';}
  if(!$why&&(!$a||!EmployerActions::valid($g,$a))){$why[]='Current authority needs checking';}return $why;
 }
 public static function access(User $u,bool $lock=false):array {
  $q=Grant::where('user_id',$u->id)->orderBy('id');if($lock){$q->lockForUpdate();}$out=[];
  foreach($q->get()as$g){$a=Apprentice::whereKey($g->apprentice_id)->when($lock,fn($q)=>$q->lockForUpdate())->first();$why=self::reasons($g,$a,$u);$out[]=['grant'=>$g,'apprentice'=>$a,'reasons'=>$why,'current'=>!$why];}return $out;
 }
 public static function scope(User $u,bool $lock=false):array {
  abort_unless($u->active&&$u->role==='employer',403);$people=[];$sealed=[];
  if($u->email_verified_at){foreach(self::access($u,$lock)as$row){if(!$row['current']){continue;}$a=$row['apprentice'];$g=$row['grant'];$e=Employer::find($g->employer_id);$people[]=['id'=>$a->id,'reference'=>$a->reference,'name'=>trim($a->first_name.' '.$a->last_name),'employer'=>$e->name];$sealed[]=['grant'=>$g->id,'version'=>$g->version,'identity'=>$g->identity_hash,'employer'=>$e->id,'employer_version'=>$e->record_version,'employer_name'=>$e->name,'apprentice'=>$a->id,'reference'=>$a->reference,'name'=>trim($a->first_name.' '.$a->last_name),'workplace'=>$a->employer_id];}}
  return ['people'=>$people,'hash'=>ReviewedImports::hash(['identity'=>EmployerActions::identity($u),'account_version'=>$u->record_version,'verified'=>(bool)$u->email_verified_at,'grants'=>$sealed])];
 }
 public static function checks(User $u,array $scope):array {
  $rows=DB::table('employer_start_checks')->where('user_id',$u->id)->get()->keyBy('item');$out=[];
  foreach(self::ITEMS as$key=>$item){$r=$rows->get($key);$out[$key]=['label'=>$item[0],'detail'=>$item[1],'version'=>$r?(int)$r->version:0,'confirmed_at'=>$r&&hash_equals($r->scope_hash,$scope['hash'])&&count($scope['people'])?$r->confirmed_at:null,'needs_recheck'=>$r&&$r->confirmed_at&&(!hash_equals($r->scope_hash,$scope['hash'])||!count($scope['people']))];}return $out;
 }
 public static function readiness(User $u):array {
  $access=self::access($u);$current=count(array_filter($access,fn($x)=>$x['current']));$issues=[];
  if(!$u->active){$issues[]='Account inactive';}if(!$u->email_verified_at){$issues[]='Mailbox unconfirmed';}if(User::whereRaw('LOWER(email) = ?',[strtolower(trim($u->email))])->count()!==1){$issues[]='Account email ambiguous';}if(!$current){$issues[]='No current apprentice authority';}
  $confirmed=0;if($u->active&&$u->role==='employer'){$scope=self::scope($u);$confirmed=count(array_filter(self::checks($u,$scope),fn($c)=>$c['confirmed_at']!==null));}
  return ['current'=>$current,'needs_recheck'=>count(array_filter($access,fn($x)=>$x['grant']->active&&!$x['current'])),'revoked'=>count(array_filter($access,fn($x)=>!$x['grant']->active)),'issues'=>$issues,'confirmed'=>$confirmed,'total'=>count(self::ITEMS),'state'=>$issues?'Needs setup':($confirmed===count(self::ITEMS)?'Getting-started checklist confirmed':'Ready for getting started')];
 }
 public static function saveCheck(User $actor,int $auth,string $item,int $version,string $scopeHash,bool $confirmed):void {
  abort_unless(isset(self::ITEMS[$item]),404);
  DB::transaction(function()use($actor,$auth,$item,$version,$scopeHash,$confirmed):void {
   $u=User::whereKey($actor->id)->lockForUpdate()->firstOrFail();abort_unless($u->active&&$u->role==='employer'&&$u->auth_version===$auth,403);$scope=self::scope($u,true);
   if(!$u->email_verified_at||!count($scope['people'])||!hash_equals($scope['hash'],$scopeHash)){self::fail('Your account or workplace access changed. Reload and ask GTA to check any missing apprentice.');}
   $q=DB::table('employer_start_checks')->where('user_id',$u->id)->where('item',$item);$old=(clone$q)->lockForUpdate()->first();if(($old?(int)$old->version:0)!==$version){self::fail('Your checklist changed. Reload before saving.');}
   if(DB::table('employer_start_events')->where('actor_id',$u->id)->where('created_at','>=',today())->count()>=100){self::fail('Please ask GTA for help before making further checklist changes today.');}
   $values=['scope_hash'=>$scope['hash'],'version'=>$version+1,'confirmed_at'=>$confirmed?now():null,'updated_at'=>now()];
   if($old){$q->update($values);$id=$old->id;}else{$id=DB::table('employer_start_checks')->insertGetId([...$values,'user_id'=>$u->id,'item'=>$item,'created_at'=>now()]);}
   DB::table('employer_start_events')->insert(['check_id'=>$id,'actor_id'=>$u->id,'event'=>$confirmed?'check_confirmed':'check_cleared','payload'=>Crypt::encryptString(json_encode(['item'=>$item,'version'=>$version+1,'scope_hash'=>$scope['hash'],'before'=>$old],JSON_THROW_ON_ERROR)),'created_at'=>now()]);
  },3);
 }

 public static function updateContact(User $actor,int $auth,User $target,int $version,array $data):void {
  DB::transaction(function()use($actor,$auth,$target,$version,$data):void {
   $users=User::whereIn('id',[$actor->id,$target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');$m=$users->get($actor->id);$u=$users->get($target->id);abort_unless($m&&LearningAccess::manager($m)&&$m->auth_version===$auth,403);abort_unless($u&&$u->role==='employer',404);
   if($u->record_version!==$version){self::fail('Contact changed. Reload before editing.');}$email=strtolower(trim($data['email']));
   if(User::whereKeyNot($u->id)->whereRaw('LOWER(email) = ?',[$email])->exists()){self::fail('This email belongs to another account. Check identity; do not merge or convert roles.');}
   $before=$u->only(['name','email','active','record_version']);$u->name=trim($data['name']);$u->email=$email;$u->active=(bool)$data['active'];if(!$u->isDirty(['name','email','active'])){return;}
   AccountAccess::invalidate($u,$m->id,'employer_contact_changed','employer_setup');$revoked=[];
   foreach(Grant::where('user_id',$u->id)->where('active',true)->orderBy('id')->lockForUpdate()->get()as$g){$g->active=false;$g->version++;$g->history=[...($g->history??[]),['event'=>'revoked_contact_changed','by'=>$m->id,'at'=>now()->toIso8601String(),'reason'=>'Employer contact account changed; check named authority again.']];$g->save();$revoked[]=$g->id;}
   DB::table('employer_start_events')->insert(['check_id'=>null,'actor_id'=>$m->id,'event'=>'contact_changed','payload'=>Crypt::encryptString(json_encode(['target_user_id'=>$u->id,'before'=>$before,'after'=>$u->only(['name','email','active','record_version']),'reason'=>$data['reason'],'revoked_grants'=>$revoked],JSON_THROW_ON_ERROR)),'created_at'=>now()]);
  },3);
 }
 public static function revokeInvitation(User $actor,int $auth,User $target,int $version,string $stamp):void {
  DB::transaction(function()use($actor,$auth,$target,$version,$stamp):void {
   $users=User::whereIn('id',[$actor->id,$target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');$m=$users->get($actor->id);$u=$users->get($target->id);abort_unless($m&&LearningAccess::manager($m)&&$m->auth_version===$auth,403);abort_unless($u&&$u->role==='employer',404);
   $token=DB::table('account_onboarding_tokens')->where('user_id',$u->id)->where('purpose','employer_invite')->lockForUpdate()->first();$now=self::invitation($u);
   if($u->record_version!==$version||!$token||!$now['stamp']||!hash_equals($now['stamp'],$stamp)){self::fail('Invitation/account changed. Reload before revoking.');}
   DB::table('account_onboarding_tokens')->where('user_id',$u->id)->where('purpose','employer_invite')->delete();AccountOnboarding::audit($u,$m->id,'employer_invite_revoked');
  },3);
 }
}
