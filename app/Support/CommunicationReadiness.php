<?php
namespace App\Support;
use App\Models\{Apprentice,User,ApprenticeAlertDelivery as Delivery};
class CommunicationReadiness
{
 public const REASONS=['preferences_off_or_other_channel'=>'Not selected or reminders switched off','account_link_or_mailbox_check'=>'Check the linked apprentice account and mailbox','encrypted_contact_needs_check'=>'The encrypted contact record needs checking','email_not_verified_unique_or_allowlisted'=>'Email needs verification, unique identity or pilot approval','mobile_not_verified_or_allowlisted'=>'Mobile needs verification or pilot approval','managed_account_blocked'=>'Current account access is held','configuration_blocked'=>'Provider configuration needs checking'];
 public const STATES=['Prepared'=>'Prepared — not sent','Dispatching'=>'Attempt reserved — do not retry automatically','Accepted'=>'Provider accepted — delivery unconfirmed','Rejected'=>'Provider rejected','Uncertain'=>'Outcome needs checking — do not retry automatically','Suppressed'=>'Not sent / suppressed','Stale'=>'Source changed or cycle expired','Cancelled'=>'Cancelled'];
 public static function person(Apprentice $a):array
 {
  $u=$a->user_id?User::find($a->user_id):null;$channels=[];
  foreach(['email','sms']as$c){$e=ApprenticeNotificationDelivery::eligible($a,$c,false);if($u&&AccountLifecycle::blocked($u)){$e=['ok'=>false,'reason'=>'managed_account_blocked'];}$channels[$c]=['eligible_contact'=>(bool)$e['ok'],'position'=>$e['ok']?'Contact prerequisites checked; source/configuration/send-time checks remain':(self::REASONS[$e['reason']]??'Contact record needs checking')];}
  $last=Delivery::where('apprentice_id',$a->id)->when($u,fn($q)=>$q->where('recipient_id',$u->id),fn($q)=>$q->whereRaw('1=0'))->orderByDesc('created_at')->limit(20)->get(['id','channel','cycle_date','state','reserved_at','completed_at','seen_at']);
  return ['reference'=>$a->reference,'name'=>$a->first_name.' '.$a->last_name,'enabled'=>(bool)$a->alerts_enabled,'channel'=>ApprenticeAlerts::CHANNELS[$a->alert_channel]??'Not recorded','mailbox_checked'=>(bool)$u?->email_verified_at,'mobile_checked'=>(bool)$a->alert_mobile_verified_at,'channels'=>$channels,'recent'=>$last->map(fn($d)=>['channel'=>$d->channel==='sms'?'Text message':'Email','date'=>$d->cycle_date?->toDateString(),'state'=>self::STATES[$d->state]??'Saved outcome needs checking','seen'=>(bool)$d->seen_at])->all()];
 }
 public static function configuration():array
 {
  $n=ApprenticeNotificationDelivery::inspect();$m=MobileVerification::inspect();$recovery=RecoveryDelivery::inspect();$control=ApprenticeNotificationDelivery::control();
  return ['reminder_blockers'=>$n['blockers'],'email_blockers'=>$n['email_blockers'],'text_blockers'=>$n['sms_blockers'],'mobile_verification_blockers'=>$m['blockers'],'recovery_blockers'=>$recovery['blockers'],'scheduler_configured'=>$n['scheduled'],'paused'=>$control['paused'],'sends_performed'=>false,'writes_performed'=>false];
 }
}
