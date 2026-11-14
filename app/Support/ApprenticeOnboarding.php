<?php
namespace App\Support;
use App\Models\{Apprentice,User};
use Illuminate\Support\Facades\{Crypt,DB};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApprenticeOnboarding
{
 public const ITEMS=[
  'details-v1'=>['Check my details','Check your name, programme and employer below. Ask GTA to correct anything wrong.','experience.home'],
  'contacts-v1'=>['Know who to contact','Check your named tutor and mentor below. Use I need help if something is unclear.','experience.index'],
  'tasks-v1'=>['Find my training tasks','Open My next step to find your next task and training day. Your tutor checks evidence.','experience.home'],
  'reviews-v1'=>['Find my reviews','Open Review signing. You check the review yourself before signing; this checklist never signs it.','electronic-reviews.index'],
  'alerts-v1'=>['Choose my reminders','Review email/text choices in Alert preferences. Changing this checklist does not change your choices.','alerts.index'],
  'help-v1'=>['Know how to ask for help','Find I need help and the Help and how to guides. Private support requests go to the support team.','experience.index'],
 ];
 public static function profile(User $u,bool $lock=false):?Apprentice {return ApprenticeExperience::profile($u,$lock);}
 public static function identity(Apprentice $a,User $u):string {
  return hash('sha256',json_encode([$a->id,$a->reference,$a->first_name,$a->last_name,strtolower(trim($a->email??'')),$a->user_id,$u->id,strtolower(trim($u->email))],JSON_THROW_ON_ERROR));
 }
 public static function inviteProfile(User $u):?Apprentice {
  $a=self::profile($u);return $a&&filter_var($a->email,FILTER_VALIDATE_EMAIL)&&strtolower(trim($a->email))===strtolower(trim($u->email))?$a:null;
 }
 public static function bindInvitation(User $u):void {
  $a=self::inviteProfile($u);abort_unless($a,403);
  DB::table('apprentice_invitation_bindings')->updateOrInsert(['user_id'=>$u->id],['apprentice_id'=>$a->id,'identity_hash'=>self::identity($a,$u),'created_at'=>now()]);
 }
 public static function validInvitation(User $u):bool {
  $a=self::inviteProfile($u);$b=DB::table('apprentice_invitation_bindings')->where('user_id',$u->id)->first();
  return $a&&$b&&(int)$b->apprentice_id===$a->id&&hash_equals($b->identity_hash,self::identity($a,$u));
 }
 private static function actor(User $u,int $auth):User {
  $actor=User::whereKey($u->id)->lockForUpdate()->firstOrFail();
  abort_unless(LearningAccess::manager($actor)&&$actor->auth_version===$auth,403);return $actor;
 }
 private static function event(Apprentice $a,?User $u,User $actor,string $event,string $reference):void {
  DB::table('apprentice_onboarding_events')->insert(['apprentice_id'=>$a->id,'user_id'=>$u?->id,'actor_id'=>$actor->id,'event'=>$event,'identity_reference'=>Crypt::encryptString($reference),'created_at'=>now()]);
 }
 public static function link(User $actor,int $auth,Apprentice $profile,array $data):User {
  return DB::transaction(function()use($actor,$auth,$profile,$data):User {
   // Stable user locks precede the profile lock, including an explicitly selected existing login.
   $ids=array_filter([$actor->id,$data['existing_user_id']??null]);User::whereIn('id',$ids)->orderBy('id')->lockForUpdate()->get();
   $actor=self::actor($actor,$auth);$a=Apprentice::whereKey($profile->id)->lockForUpdate()->firstOrFail();
   if($a->version!==(int)$data['version']||$a->user_id){throw ValidationException::withMessages(['onboarding'=>'Profile changed or already linked. Reload and check the identity.']);}
   $email=strtolower(trim($a->email??''));
   if(!filter_var($email,FILTER_VALIDATE_EMAIL)){throw ValidationException::withMessages(['onboarding'=>'Correct the apprentice email on the profile first.']);}
   if($data['mode']==='existing') {
    $u=User::find($data['existing_user_id']);
    if(!$u||!$u->active||$u->role!=='apprentice'||$u->record_version!==(int)$data['record_version']||strtolower(trim($u->email))!==$email||Apprentice::where('user_id',$u->id)->exists()) {
     throw ValidationException::withMessages(['onboarding'=>'Choose an active, unlinked apprentice login with the same individual email and current account version. Other roles cannot be converted.']);
    }
    AccountAccess::invalidate($u,$actor->id,'apprentice_profile_linked','apprentice_onboarding');
   }else {
    if(User::whereRaw('LOWER(email) = ?',[$email])->exists()){throw ValidationException::withMessages(['onboarding'=>'This email already has a login. Check the exact account; do not create a duplicate.']);}
    $u=new User(['name'=>trim($a->first_name.' '.$a->last_name),'email'=>$email,'password'=>Str::password(48)]);$u->role='apprentice';$u->email_verified_at=null;$u->save();
   }
   if(User::whereRaw('LOWER(email) = ?',[$email])->count()!==1){throw ValidationException::withMessages(['onboarding'=>'The email is ambiguous. Ask GTA to correct the account records.']);}
   DB::table('apprentice_invitation_bindings')->where('user_id',$u->id)->orWhere('apprentice_id',$a->id)->delete();
   $a->user_id=$u->id;$a->version++;$a->save();self::event($a,$u,$actor,'account_linked',$data['identity_reference']);return $u;
  });
 }
 public static function detach(User $actor,int $auth,Apprentice $profile,array $data):void {
  DB::transaction(function()use($actor,$auth,$profile,$data):void {
   $targetId=$profile->user_id;User::whereIn('id',array_filter([$actor->id,$targetId]))->orderBy('id')->lockForUpdate()->get();
   $actor=self::actor($actor,$auth);$a=Apprentice::whereKey($profile->id)->lockForUpdate()->firstOrFail();
   if($a->version!==(int)$data['version']||!$targetId||(int)$a->user_id!==(int)$targetId){throw ValidationException::withMessages(['onboarding'=>'Profile changed. Reload before unlinking.']);}
   $u=User::find($targetId);abort_unless($u&&$u->role==='apprentice',409);
   AccountAccess::invalidate($u,$actor->id,'apprentice_profile_unlinked','apprentice_onboarding');
   DB::table('apprentice_invitation_bindings')->where('user_id',$u->id)->delete();$a->user_id=null;$a->version++;$a->save();self::event($a,$u,$actor,'account_unlinked',$data['identity_reference']);
  });
 }
 public static function checks(Apprentice $a):array {
  $rows=DB::table('apprentice_start_checks')->where('apprentice_id',$a->id)->where('user_id',$a->user_id)->get()->keyBy('item');$out=[];
  foreach(self::ITEMS as $key=>$item){$r=$rows->get($key);$out[$key]=['label'=>$item[0],'detail'=>$item[1],'route'=>$item[2],'version'=>$r?(int)$r->version:0,'confirmed_at'=>$r?->confirmed_at];}return $out;
 }
 public static function saveCheck(User $u,int $auth,string $item,int $version,bool $confirmed):void {
  abort_unless(isset(self::ITEMS[$item]),404);
  DB::transaction(function()use($u,$auth,$item,$version,$confirmed):void {
   $u=User::whereKey($u->id)->lockForUpdate()->firstOrFail();abort_unless($u->auth_version===$auth,403);
   $a=self::profile($u,true);abort_unless($a&&$u->email_verified_at,409);
   $q=DB::table('apprentice_start_checks')->where('apprentice_id',$a->id)->where('user_id',$u->id)->where('item',$item);$r=(clone $q)->lockForUpdate()->first();
   if(($r?(int)$r->version:0)!==$version){throw ValidationException::withMessages(['check'=>'Your checklist changed. Reload before saving.']);}
   $eventQuery=DB::table('apprentice_start_check_events')->where('actor_id',$u->id)->where('created_at','>=',now()->startOfDay());
   if($eventQuery->count()>=100){throw ValidationException::withMessages(['check'=>'Please ask GTA for help with your checklist before making further changes today.']);}
   $values=['version'=>$version+1,'confirmed_at'=>$confirmed?now():null,'updated_at'=>now()];
   if($r){$q->update($values);$id=$r->id;}else{$id=DB::table('apprentice_start_checks')->insertGetId([...$values,'apprentice_id'=>$a->id,'user_id'=>$u->id,'item'=>$item,'created_at'=>now()]);}
   DB::table('apprentice_start_check_events')->insert(['check_id'=>$id,'actor_id'=>$u->id,'confirmed'=>$confirmed,'check_version'=>$version+1,'created_at'=>now()]);
  });
 }
 public static function readiness(Apprentice $a):array {
  $u=$a->user_id?User::find($a->user_id):null;$issues=[];
  if(!$u){$issues[]='Login not linked';}elseif(!$u->active||$u->role!=='apprentice'||Apprentice::where('user_id',$u->id)->count()!==1){$issues[]='Account link needs correction';}
  if(!$u?->email_verified_at){$issues[]='Mailbox not confirmed';}
  if(!filter_var($a->email,FILTER_VALIDATE_EMAIL)||($u&&strtolower(trim($a->email??''))!==strtolower(trim($u->email)))){$issues[]='Individual email needs checking';}
  if(!$a->programme_id||!$a->programmeRecord?->active){$issues[]='Active programme not assigned';}
  if(!$a->tutor_id||!$a->tutor?->active||$a->tutor->role!=='tutor'){$issues[]='Main tutor assignment needs checking';}
  if(!$a->start_date||!$a->planned_end_date||$a->planned_end_date->lessThan($a->start_date)){$issues[]='Training dates need checking';}
  $checks=self::checks($a);$confirmed=count(array_filter($checks,fn($c)=>$c['confirmed_at']!==null));
  return ['issues'=>$issues,'state'=>$issues?'Needs setup':($confirmed===count(self::ITEMS)?'Started':'Ready to start'),'confirmed'=>$confirmed,'total'=>count(self::ITEMS)];
 }
}
