<?php
namespace App\Support;
use App\Models\{Apprentice,ApprenticeMobileChallenge as Challenge,User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache,DB,Hash,Http};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MobileVerification {
 public const ENDPOINT='https://rest.nexmo.com/sms/json';
 public static function digest(string $value):string {return hash_hmac('sha256',$value,(string)config('app.key'));}
 private static function binding():string {return self::digest(json_encode([config('mobile_verification.key'),config('mobile_verification.secret'),config('mobile_verification.from'),config('mobile_verification.recipients')],JSON_THROW_ON_ERROR));}
 public static function errors():array {
  $errors=StagingAccess::errors();if(config('mobile_verification.enabled')!==true){$errors[]='mobile_verification_disabled';}
  $list=config('mobile_verification.recipients');if(!is_array($list)||!$list||count($list)>10||collect($list)->contains(fn($n)=>!is_string($n)||!preg_match('/^\+[1-9][0-9]{7,14}$/',$n))){$errors[]='bounded_e164_test_recipient_list_required';}
  if(!is_string(config('mobile_verification.key'))||!preg_match('/^[A-Za-z0-9]{8,64}$/',config('mobile_verification.key'))){$errors[]='vonage_key_required';}
  $secret=config('mobile_verification.secret');if(!is_string($secret)||strlen($secret)<8||strlen($secret)>128){$errors[]='vonage_secret_required';}
  $from=config('mobile_verification.from');if(!is_string($from)||!preg_match('/^(?:\+?[1-9][0-9]{7,14}|[A-Za-z][A-Za-z0-9 ]{0,10})$/',$from)){$errors[]='vonage_sender_required';}
  return array_values(array_unique($errors));
 }
 public static function inspect():array {return ['mode'=>'staging_apprentice_mobile_verification','result'=>self::errors()?'blocked':'configuration_checks_only','blockers'=>self::errors(),'recipient_count'=>is_array(config('mobile_verification.recipients'))?count(config('mobile_verification.recipients')):0,'sends_performed'=>false,'writes_performed'=>false,'limits'=>['No provider connection or handset delivery tested.','Verifies contact possession only; does not implement task/review reminders or login MFA.']];}
 private static function fail(string $message):never {throw ValidationException::withMessages(['mobile_code'=>$message]);}
 private static function permitted(Request $r):void {
  abort_unless($r->user()?->active&&$r->user()->role==='apprentice',403);
  if(self::errors()||!$r->isSecure()||!StagingAccess::requestAllowed($r)||!$r->hasSession()){self::fail('Mobile verification is not available in this environment. Contact GTA.');}
 }
 private static function profile(Request $r):Apprentice {
  // Match preference writes: profile before actor, avoiding an inverted lock order.
  $people=Apprentice::where('user_id',$r->user()->id)->lockForUpdate()->get();if($people->count()!==1){self::fail('Ask GTA to check the apprentice account link.');}
  $user=User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
  abort_unless($user->active&&$user->role==='apprentice'&&$user->auth_version===$r->session()->get('auth_version'),403);
  $a=$people->first();if(!ApprenticeAlerts::wants($a,'sms')||!in_array($a->alert_mobile,config('mobile_verification.recipients',[]),true)){self::fail('Select Text or Both with reminders on and an approved test mobile before requesting a code.');}
  return $a;
 }
 public static function issue(Request $r):string {
  self::permitted($r);if(DB::transactionLevel()!==0){self::fail('A verification request cannot be sent inside another transaction.');}
  $code=(string)random_int(10000000,99999999);$id=(string)Str::uuid();
  try {$reserved=Cache::lock('gta-apprentice-mobile-verification-reserve',30)->block(3,function()use($r,$code,$id) {
   return DB::transaction(function()use($r,$code,$id) {
    $a=self::profile($r);if($a->alert_mobile_verified_at){self::fail('This saved mobile is already verified.');}
    $phoneHash=self::digest($a->alert_mobile);$now=now();
    if(Challenge::where('user_id',$r->user()->id)->where('created_at','>',$now->copy()->subSeconds(60))->exists()){self::fail('Wait at least one minute before requesting another code.');}
    if(Challenge::where('user_id',$r->user()->id)->where('created_at','>=',$now->copy()->subHours(24))->count()>=5||Challenge::where('phone_hash',$phoneHash)->where('created_at','>=',$now->copy()->subHours(24))->count()>=5||Challenge::where('created_at','>=',$now->copy()->subHours(24))->count()>=50){self::fail('The verification request limit has been reached. Try later or contact GTA.');}
    Challenge::where('apprentice_id',$a->id)->whereIn('state',['Reserved','Accepted'])->update(['state'=>'Superseded','code_hash'=>null,'updated_at'=>now()]);
    Challenge::create(['id'=>$id,'user_id'=>$r->user()->id,'apprentice_id'=>$a->id,'phone_hash'=>$phoneHash,'session_hash'=>self::digest($r->session()->getId()),'config_hash'=>self::binding(),'preference_revision'=>$a->alert_revision,'auth_version'=>$r->user()->auth_version,'code_hash'=>Hash::make(self::digest($id.':'.$code)),'state'=>'Reserved','expires_at'=>$now->copy()->addMinutes(10)]);
    return ['id'=>$id,'phone'=>$a->alert_mobile];
   });
  });}catch(\Illuminate\Contracts\Cache\LockTimeoutException){self::fail('Verification is busy. Wait and try again.');}
  // Reservation commits first; lock and recheck settings again across the one bounded HTTP attempt.
  return DB::transaction(function()use($r,$reserved,$id,$code):string {
   self::permitted($r);$a=self::profile($r);$c=Challenge::whereKey($id)->lockForUpdate()->firstOrFail();
   if($c->state!=='Reserved'||$c->expires_at->lte(now())||$c->preference_revision!==$a->alert_revision||!hash_equals($c->phone_hash,self::digest($a->alert_mobile))||!hash_equals($c->config_hash,self::binding())) {
    if($c->state==='Reserved'){$c->state='Superseded';$c->code_hash=null;$c->save();}return 'Superseded';
   }
   $state='Uncertain';$provider=null;
   try {
    $response=Http::asForm()->withBasicAuth(config('mobile_verification.key'),config('mobile_verification.secret'))->withOptions(['verify'=>true,'allow_redirects'=>false])->connectTimeout(5)->timeout(10)
     ->post(self::ENDPOINT,['from'=>config('mobile_verification.from'),'to'=>ltrim($reserved['phone'],'+'),'text'=>'GTA mobile verification code: '.$code.'. Expires in 10 minutes. Do not share it.','client-ref'=>$id]);
    $body=$response->json();$message=$body['messages'][0]??null;
    if($response->successful()&&($body['message-count']??null)==='1'&&is_array($message)) {
     if(($message['status']??null)==='0'&&($message['to']??null)===ltrim($reserved['phone'],'+')&&is_string($message['message-id']??null)&&preg_match('/^[A-Za-z0-9_-]{1,64}$/',$message['message-id'])){$state='Accepted';$provider=$message['message-id'];}
     elseif(is_string($message['status']??null)&&preg_match('/^[1-9][0-9]{0,3}$/',$message['status'])){$state='Rejected';}
    }
   }catch(\Throwable){/* No raw provider exception/request, OTP, credentials or phone are logged here. */}
   $c->state=$state;$c->provider_id=$provider;if($state!=='Accepted'){$c->code_hash=null;}$c->save();
   return $state;
  });
 }

 public static function confirm(Request $r,string $id,string $code):void {
  self::permitted($r);
  $result=DB::transaction(function()use($r,$id,$code):bool {
   $a=self::profile($r);$c=Challenge::whereKey($id)->where('user_id',$r->user()->id)->where('apprentice_id',$a->id)->lockForUpdate()->first();
   if(!$c||!hash_equals($c->session_hash,self::digest($r->session()->getId()))){return false;}
   if($c->state!=='Accepted'){return false;}
   if(!is_string($c->code_hash)||$c->expires_at->lte(now())||$c->preference_revision!==$a->alert_revision||$c->auth_version!==$r->user()->auth_version||!hash_equals($c->phone_hash,self::digest($a->alert_mobile))||!hash_equals($c->config_hash,self::binding())){$c->state='Superseded';$c->code_hash=null;$c->save();return false;}
   $c->attempts++;
   if(!Hash::check(self::digest($id.':'.$code),$c->code_hash)) {if($c->attempts>=5){$c->state='Locked';$c->code_hash=null;}$c->save();return false;}
   $a->alert_mobile_verified_at=now();$a->alert_revision++;$a->version++;
   $same=['channel'=>$a->alert_channel,'enabled'=>$a->alerts_enabled];$a->alert_history=[...($a->alert_history??[]),['event'=>'mobile_verified','before'=>$same,'after'=>$same,'phone_changed'=>false,'by'=>$r->user()->id,'at'=>now()->toIso8601String(),'origin'=>'mobile_verification']];$a->save();
   $c->state='Consumed';$c->code_hash=null;$c->save();DB::table('audit_events')->insert(['user_id'=>$r->user()->id,'apprentice_id'=>$a->id,'event'=>'mobile_verified','created_at'=>now()]);return true;
  });
  if(!$result){self::fail('The code is invalid, expired or no longer current. Reopen your preferences and check before requesting another.');}
 }
 public static function history():array {
  try {
   $counts=array_fill_keys(['Reserved','Accepted','Rejected','Uncertain','Consumed','Locked','Superseded','Other'],0);
   foreach(Challenge::select('state')->selectRaw('COUNT(*) AS total')->groupBy('state')->get()as$row){$key=array_key_exists($row->state,$counts)?$row->state:'Other';$counts[$key]+=(int)$row->total;}
   return ['result'=>'recorded_state_counts_only','states'=>$counts,'last_24_hours'=>Challenge::where('created_at','>=',now()->subHours(24))->count(),'writes_performed'=>false,'note'=>'Recorded states, not verified current eligibility or handset delivery; expired records may retain earlier states.'];
  }catch(\Throwable){return ['result'=>'blocked','reason'=>'history_schema_or_connection_unavailable','writes_performed'=>false];}
 }
 public static function position(Request $r,?Apprentice $a):array {
  $position=['available'=>false,'verified'=>(bool)$a?->alert_mobile_verified_at,'state'=>null,'id'=>null];
  if(!$a||self::errors()||!ApprenticeAlerts::wants($a,'sms')||!in_array($a->alert_mobile,config('mobile_verification.recipients',[]),true)){return $position;}
  $position['available']=true;$c=Challenge::where('user_id',$r->user()->id)->where('apprentice_id',$a->id)->orderByDesc('created_at')->orderByDesc('id')->first();
  if($c&&hash_equals($c->session_hash,self::digest($r->session()->getId()))){$position['state']=$c->expires_at->lte(now())?'Expired':$c->state;if($c->state==='Accepted'&&$c->expires_at->gt(now())&&$c->preference_revision===$a->alert_revision){$position['id']=$c->id;}}
  return $position;
 }
}
