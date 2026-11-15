<?php
namespace App\Http\Controllers;
use App\Models\{Apprentice,EmployerApprenticeGrant,SupportPlan,WorkplaceSupportShare as Share};
use App\Support\{ApprenticeExperience,SupportAccess,WorkplaceSupportSharing as Sharing,ReviewedImports};
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkplaceSupportSharingController extends Controller {
 private function privateView(string $name,array $data=[]) {return response()->view('workplace-support.'.$name,$data)->header('Cache-Control','no-store, private')->header('Referrer-Policy','no-referrer');}
 public function index(Request $r) {
  $u=$r->user();abort_unless($u->active&&($u->role==='apprentice'||$u->role==='employer'||SupportAccess::team($u)),403);
  if($u->role==='employer') {
   // Count/paginate only currently visible approved summaries, never pending/private rows.
   $all=Share::where('recipient_id',$u->id)->where('state','Shared')->orderByDesc('created_at')->get()->filter(fn($s)=>Sharing::employerVisible($u,$s))->values();
   $page=max(1,(int)$r->query('page',1));$shares=new LengthAwarePaginator($all->forPage($page,15),$all->count(),15,$page,['path'=>route('workplace-support.index')]);$audience='employer';
  }elseif($u->role==='apprentice') {
   abort_unless($u->email_verified_at,403);$a=ApprenticeExperience::profile($u);abort_unless($a,409,'Ask GTA to check your apprentice account link.');
   $shares=Share::where('apprentice_id',$a->id)->where('learner_id',$u->id)->where('state','!=','Draft')->orderByDesc('created_at')->paginate(15);$audience='apprentice';
  }else{$shares=Share::whereIn('apprentice_id',SupportAccess::ids($u))->orderByDesc('created_at')->paginate(15);$audience='staff';}
  $cards=[];foreach($shares as$s){
   try{$payload=$s->payload;$source=$s->source;if(ReviewedImports::hash($payload)!==$s->payload_hash||ReviewedImports::hash($source)!==$s->source_hash){throw new \RuntimeException();}$cards[$s->id]=['title'=>$payload['title'],'person'=>trim($source['profile']['first_name'].' '.$source['profile']['last_name']),'recipient'=>$source['recipient']['name']];}catch(\Throwable){$cards[$s->id]=['title'=>'Summary needs checking','person'=>'','recipient'=>''];}
  }return $this->privateView('index',compact('shares','audience','cards'));
 }
 public function create(Request $r,Apprentice $apprentice) {
  abort_unless(SupportAccess::team($r->user(),$apprentice),403);
  $plans=SupportPlan::where('apprentice_id',$apprentice->id)->where('status','Active')->whereNotNull('active_plan')->get();
  $grants=EmployerApprenticeGrant::where('apprentice_id',$apprentice->id)->where('active',true)->get()->filter(fn($g)=>\App\Support\EmployerActions::valid($g,$apprentice));
  return $this->privateView('create',['apprentice'=>$apprentice,'plans'=>$plans,'grants'=>$grants,'writer'=>SupportAccess::writer($r->user(),$apprentice),'requestToken'=>(string)Str::uuid()]);
 }
 public function store(Request $r,Apprentice $apprentice) {
  abort_unless(SupportAccess::writer($r->user(),$apprentice),403);
  $d=$r->validate(['request_token'=>['required','uuid'],'plan_choice'=>['required','regex:/^[1-9][0-9]*:[1-9][0-9]*$/D'],'grant_choice'=>['required','regex:/^[1-9][0-9]*:[1-9][0-9]*$/D'],'title'=>['required','string','max:160'],'adjustments'=>['required','string','max:4000'],'employer_actions'=>['required','string','max:4000'],'expires_on'=>['required','date_format:Y-m-d','after_or_equal:today','before_or_equal:'.today()->addDays(30)->toDateString()],'confirmed'=>['accepted']]);
  [$d['plan_id'],$d['plan_version']]=array_map('intval',explode(':',$d['plan_choice']));[$d['grant_id'],$d['grant_version']]=array_map('intval',explode(':',$d['grant_choice']));
  $s=Sharing::prepare($r->user(),(int)$r->session()->get('auth_version'),$apprentice,$d);return to_route('workplace-support.show',$s)->with('status','Private summary draft prepared. No employer access or message. An Inclusion lead must check it before the apprentice can decide.');
 }
 private function audience(Request $r,Share $s):string {
  $a=Apprentice::findOrFail($s->apprentice_id);$u=$r->user();
  if(Sharing::employerVisible($u,$s)){return 'employer';}
  if(Sharing::learner($u,$s,$a)&&$s->state!=='Draft'){return 'apprentice';}
  if(Sharing::staff($u,$a)){return 'staff';}abort(404);
 }
 public function show(Request $r,Share $share) {
  $audience=$this->audience($r,$share);
  try {$payload=$share->payload;$source=$share->source;$readable=ReviewedImports::hash($payload)===$share->payload_hash&&ReviewedImports::hash($source)===$share->source_hash;}catch(\Throwable){$readable=false;}
  abort_unless($readable,409,'This summary cannot be verified. Ask GTA to check it.');
  $a=Apprentice::findOrFail($share->apprentice_id);$current=Sharing::current($share);
  return $this->privateView('show',['share'=>$share,'payload'=>$payload,'source'=>$source,'audience'=>$audience,'current'=>$current,'canCheck'=>$audience==='staff'&&SupportAccess::approver($r->user(),$a),'canWithdraw'=>$audience==='apprentice'||($audience==='staff'&&SupportAccess::writer($r->user(),$a)),'events'=>$audience==='staff'?DB::table('workplace_support_share_events')->where('share_id',$share->id)->orderBy('id')->get():collect()]);
 }
 public function check(Request $r,Share $share) {
  $d=$r->validate(['version'=>['required','integer','min:1'],'confirmed'=>['accepted']]);Sharing::requestPermission($r->user(),(int)$r->session()->get('auth_version'),$share,(int)$d['version']);return back()->with('status','Exact checked summary is now available for the apprentice to allow or decline. No message or employer access yet.');
 }
 public function decide(Request $r,Share $share) {
  $d=$r->validate(['version'=>['required','integer','min:1'],'decision'=>['required','in:allow,decline'],'confirmed'=>['required_if:decision,allow','accepted_if:decision,allow']]);Sharing::decide($r->user(),(int)$r->session()->get('auth_version'),$share,(int)$d['version'],$d['decision']);return back()->with('status',$d['decision']==='allow'?'Only this exact summary is now available to the named employer until its expiry or earlier withdrawal.':'Sharing declined. No employer access has been granted.');
 }
 public function withdraw(Request $r,Share $share) {
  $d=$r->validate(['version'=>['required','integer','min:1'],'confirmed'=>['accepted']]);Sharing::withdraw($r->user(),(int)$r->session()->get('auth_version'),$share,(int)$d['version']);return back()->with('status','Summary withdrawn. Further portal access is stopped. Copies already seen or saved cannot be recalled.');
 }
 public function acknowledge(Request $r,Share $share) {
  $d=$r->validate(['version'=>['required','integer','min:1'],'confirmed'=>['accepted']]);Sharing::acknowledge($r->user(),(int)$r->session()->get('auth_version'),$share,(int)$d['version']);return back()->with('status','Receipt acknowledged. This does not confirm that support actions were completed.');
 }
}
