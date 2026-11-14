<?php
namespace App\Support;
use App\Models\{Apprentice,User};
use Illuminate\Validation\ValidationException;
class ApprenticeAlerts {
 public const CHANNELS=['email'=>'Email','sms'=>'Text message','both'=>'Both — email and text message'];
 public const INPUTS=['change_alert_preferences','alert_channel','alerts_enabled','alert_mobile','alert_revision'];
 public static function normalise(?string $phone):?string {
  if($phone===null||trim($phone)===''){return null;}$phone=preg_replace('/[ ()-]/','',trim($phone));
  return str_starts_with($phone,'07')&&strlen($phone)===11?'+44'.substr($phone,1):$phone;
 }
 // Selection only. Real sending additionally requires verified contact, current source, provider and send-time opt-out checks.
 public static function wants(Apprentice $a,string $channel):bool {
  return in_array($channel,['email','sms'],true)&&$a->alerts_enabled===true&&in_array($a->alert_channel,[$channel,'both'],true);
 }
 // Caller holds apprentice lock/transaction; no raw phone number is written to history.
 public static function apply(Apprentice $a,User $actor,int $actorVersion,array $data):bool {
  $u=User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
  abort_unless($u->active&&$u->auth_version===$actorVersion&&(LearningAccess::manager($u)||($u->role==='apprentice'&&$a->user_id===$u->id)),403);
  if($a->alert_revision!==(int)$data['alert_revision']){throw ValidationException::withMessages(['alert_revision'=>'Alert preferences changed. Reopen the current record before saving.']);}
  $lastSelf=collect($a->alert_history??[])->filter(fn($entry)=>($entry['origin']??null)==='apprentice_self_service')->last();
  if($u->role!=='apprentice'&&(bool)$data['alerts_enabled']&&($lastSelf['after']['enabled']??null)===false) {
   throw ValidationException::withMessages(['alerts_enabled'=>'The apprentice switched reminders off. They can turn them back on from their own Alert preferences page.']);
  }
  $phone=self::normalise($data['alert_mobile']??null);$enabled=(bool)$data['alerts_enabled'];$before=['channel'=>$a->alert_channel,'enabled'=>$a->alerts_enabled];$after=['channel'=>$data['alert_channel'],'enabled'=>$enabled];
  $phoneChanged=$a->alert_mobile!==$phone;if($before===$after&&!$phoneChanged){return false;}
  $a->alert_channel=$data['alert_channel'];$a->alerts_enabled=$enabled;$a->alert_mobile=$phone;
  if($phoneChanged){$a->alert_mobile_verified_at=null;}
  $a->alert_revision++;$a->alert_history=[...($a->alert_history??[]),['before'=>$before,'after'=>$after,'phone_changed'=>$phoneChanged,'by'=>$u->id,'at'=>now()->toIso8601String(),'origin'=>$u->role==='apprentice'?'apprentice_self_service':'management_setup']];
  return true;
 }
 public static function rules():array {return ['alert_channel'=>['required',\Illuminate\Validation\Rule::in(array_keys(self::CHANNELS))],'alerts_enabled'=>['required','boolean'],'alert_revision'=>['required','integer','min:0'],'alert_mobile'=>['nullable',\Illuminate\Validation\Rule::requiredIf(fn()=>request()->boolean('alerts_enabled')&&in_array(request()->input('alert_channel'),['sms','both'],true)),'string','regex:/^\+[1-9][0-9]{7,14}$/']];}
}
