<?php
namespace App\Http\Controllers;
use App\Models\{Apprentice,User};
use App\Support\{AccountAccess,AccountOnboarding,ApprenticeOnboarding as StartUp,LearningAccess};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,RateLimiter};
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class ApprenticeOnboardingController extends Controller
{
 private function manager(Request $r):void {abort_unless(LearningAccess::manager($r->user()),403);}
 private function privateView(string $view,array $data=[],int $status=200) {return response()->view('apprentice-onboarding.'.$view,$data,$status)->header('Cache-Control','no-store, private')->header('Referrer-Policy','no-referrer');}
 public function index(Request $r) {
  $this->manager($r);$data=$r->validate(['search'=>['nullable','string','max:120'],'state'=>['nullable','in:all,unlinked,unconfirmed,confirmed']]);$search=trim($data['search']??'');$state=$data['state']??'all';
  $q=Apprentice::query()->when($search!=='',fn($q)=>$q->where(function($q)use($search){foreach(['reference','first_name','last_name']as$field){$q->orWhere($field,'like','%'.$search.'%');}}));
  if($state==='unlinked'){$q->whereNull('user_id');}
  if(in_array($state,['unconfirmed','confirmed'],true)){$ids=User::where('role','apprentice')->when($state==='confirmed',fn($q)=>$q->whereNotNull('email_verified_at'),fn($q)=>$q->whereNull('email_verified_at'))->select('id');$q->whereIn('user_id',$ids);}
  $people=$q->orderBy('last_name')->orderBy('id')->paginate(20)->withQueryString();$readiness=[];foreach($people as$a){$readiness[$a->id]=StartUp::readiness($a);}
  return $this->privateView('index',compact('people','readiness','search','state'));
 }
 public function show(Request $r,Apprentice $apprentice) {
  $this->manager($r);$user=$apprentice->user_id?User::find($apprentice->user_id):null;
  $candidates=$apprentice->user_id?collect():User::where('role','apprentice')->where('active',true)->whereRaw('LOWER(email) = ?',[strtolower(trim($apprentice->email??''))])->whereNotIn('id',Apprentice::whereNotNull('user_id')->select('user_id'))->get();
  $token=$user?DB::table('account_onboarding_tokens')->where('user_id',$user->id)->where('purpose','learner_invite')->first(['created_at','expires_at']):null;
  return $this->privateView('show',['apprentice'=>$apprentice,'user'=>$user,'candidates'=>$candidates,'readiness'=>StartUp::readiness($apprentice),'checks'=>StartUp::checks($apprentice),'token'=>$token,'events'=>DB::table('apprentice_onboarding_events')->where('apprentice_id',$apprentice->id)->orderByDesc('id')->limit(30)->get(['event','created_at','actor_id','user_id'])]);
 }
 public function link(Request $r,Apprentice $apprentice) {
  $this->manager($r);$data=$r->validate(['version'=>['required','integer','min:1'],'mode'=>['required',Rule::in(['new','existing'])],'existing_user_id'=>['required_if:mode,existing','prohibited_if:mode,new','nullable','integer','min:1'],'record_version'=>['required_if:mode,existing','prohibited_if:mode,new','nullable','integer','min:1'],'identity_reference'=>['required','string','max:2000'],'confirmed'=>['accepted']]);
  StartUp::link($r->user(),(int)$r->session()->get('auth_version'),$apprentice,$data);
  return back()->with('status','Apprentice login linked. No invitation was sent. Check the account, then send the controlled invitation separately if needed.');
 }
 public function detach(Request $r,Apprentice $apprentice) {
  $this->manager($r);$data=$r->validate(['version'=>['required','integer','min:1'],'identity_reference'=>['required','string','max:2000'],'confirmed'=>['accepted']]);
  StartUp::detach($r->user(),(int)$r->session()->get('auth_version'),$apprentice,$data);return back()->with('status','Profile unlinked. Existing sessions and links invalidated. The account and training records are retained.');
 }
 public function invite(Request $r,Apprentice $apprentice) {
  $this->manager($r);$data=$r->validate(['version'=>['required','integer'],'record_version'=>['required','integer']]);
  $key='apprentice-invite:'.$r->user()->id;abort_if(RateLimiter::tooManyAttempts($key,5),429);RateLimiter::hit($key,300);
  try {DB::transaction(function()use($r,$apprentice,$data):void {
   $rows=User::whereIn('id',array_filter([$r->user()->id,$apprentice->user_id]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
   $actor=$rows->get($r->user()->id);$a=Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();$u=$rows->get($a->user_id);
   abort_unless($actor&&LearningAccess::manager($actor)&&$actor->auth_version===$r->session()->get('auth_version'),403);
   if($a->version!==(int)$data['version']||!$u||$u->record_version!==(int)$data['record_version']){throw ValidationException::withMessages(['onboarding'=>'Profile or account changed. Reload before sending.']);}
   AccountOnboarding::issue($u,'learner_invite',$actor);
  });}catch(TransportExceptionInterface){return back()->withErrors(['onboarding'=>'Invitation transport did not accept the request. No new invitation was saved. Contact GTA.']);}
  return back()->with('status','Controlled apprentice invitation prepared. Any previous invitation was replaced. Mailbox delivery is not guaranteed.');
 }
 public function revoke(Request $r,Apprentice $apprentice) {
  $this->manager($r);$data=$r->validate(['version'=>['required','integer']]);
  DB::transaction(function()use($r,$apprentice,$data):void {
   $rows=User::whereIn('id',array_filter([$r->user()->id,$apprentice->user_id]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');$actor=$rows->get($r->user()->id);$a=Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();
   abort_unless($actor&&LearningAccess::manager($actor)&&$actor->auth_version===$r->session()->get('auth_version'),403);
   if($a->version!==(int)$data['version']){throw ValidationException::withMessages(['onboarding'=>'Profile changed. Reload first.']);}
   $u=$rows->get($a->user_id);if($u&&$u->role==='apprentice'){$count=DB::table('account_onboarding_tokens')->where('user_id',$u->id)->where('purpose','learner_invite')->delete();DB::table('apprentice_invitation_bindings')->where('user_id',$u->id)->delete();if($count){AccountOnboarding::audit($u,$actor->id,'learner_invite_revoked');}}
  });return back()->with('status','Any outstanding apprentice invitation revoked. You can revoke even while the mail pilot is switched off.');
 }
 public function acceptForm(Request $r,int $user,string $token) {
  $u=User::find($user);abort_unless($u&&AccountOnboarding::valid($u,'learner_invite',$token),403,
   'This invitation is unavailable. Ask GTA for a new invitation, or sign in if you have already set your password.');return $this->privateView('invitation',compact('user','token'));
 }
 public function accept(Request $r,int $user,string $token) {
  $key='learner-accept:'.hash('sha256',$r->ip());abort_if(RateLimiter::tooManyAttempts($key,10),429);RateLimiter::hit($key,300);
  $data=$r->validate(['password'=>AccountAccess::passwordRules()]);
  DB::transaction(function()use($user,$token,$data):void {
   $issuer=DB::table('account_onboarding_tokens')->where('user_id',$user)->where('purpose','learner_invite')->value('issuer_id');
   $rows=User::whereIn('id',array_filter([$user,$issuer]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');$u=$rows->get($user);
   abort_unless($u&&StartUp::profile($u,true)&&(int)DB::table('account_onboarding_tokens')->where('user_id',$user)->where('purpose','learner_invite')->value('issuer_id')===(int)$issuer&&AccountOnboarding::valid($u,'learner_invite',$token,true),403);
   $u->email_verified_at=now();AccountAccess::setPassword($u,$data['password'],null,'apprentice_invitation');DB::table('apprentice_invitation_bindings')->where('user_id',$u->id)->delete();AccountOnboarding::audit($u,null,'learner_invite_accepted');
  });$r->session()->invalidate();$r->session()->regenerateToken();return to_route('login')->with('status','Your apprentice password is set and mailbox confirmed. Sign in, then open Getting started.');
 }
 public function home(Request $r) {
  abort_unless($r->user()->active&&$r->user()->role==='apprentice',403);$apprentice=StartUp::profile($r->user());return $this->privateView('home',['apprentice'=>$apprentice,'checks'=>$apprentice?StartUp::checks($apprentice):[]],$apprentice?200:409);
 }
 public function check(Request $r,string $item) {
  abort_unless($r->user()->active&&$r->user()->role==='apprentice',403);$data=$r->validate(['version'=>['required','integer','min:0'],'confirmed'=>['required','boolean']]);
  StartUp::saveCheck($r->user(),(int)$r->session()->get('auth_version'),$item,(int)$data['version'],(bool)$data['confirmed']);return back()->with('status','Getting started checklist updated. This does not sign a review or change your alert preferences.');
 }
}
