<?php
namespace App\Http\Controllers;
use App\Models\User;
use App\Support\{EmployerSetup as Setup,LearningAccess};
use Illuminate\Http\Request;
class EmployerSetupController extends Controller {
 private function manager(Request $r):void {abort_unless(LearningAccess::manager($r->user()),403);}
 private function view(string $name,array $data=[]) {return response()->view('employer-setup.'.$name,$data)->header('Cache-Control','no-store, private')->header('Referrer-Policy','no-referrer');}
 public function index(Request $r) {
  $this->manager($r);$d=$r->validate(['search'=>['nullable','string','max:120'],'state'=>['nullable','in:all,active,inactive,unconfirmed'],'page'=>['nullable','integer','min:1']]);$search=trim($d['search']??'');$state=$d['state']??'all';$q=User::where('role','employer');
  if($search!==''){$needle='%'.str_replace(['!','%','_'],['!!','!%','!_'],mb_strtolower($search)).'%';$q->where(fn($q)=>$q->whereRaw("LOWER(name) LIKE ? ESCAPE '!'",[$needle])->orWhereRaw("LOWER(email) LIKE ? ESCAPE '!'",[$needle]));}if($state==='active'){$q->where('active',true);}if($state==='inactive'){$q->where('active',false);}if($state==='unconfirmed'){$q->whereNull('email_verified_at');}
  $users=$q->orderBy('name')->orderBy('id')->paginate(20)->withQueryString();$positions=[];foreach($users as$u){$positions[$u->id]=Setup::readiness($u);}return $this->view('index',compact('users','positions','search','state'));
 }
 public function show(Request $r,User $user) {$this->manager($r);abort_unless($user->role==='employer',404);return $this->view('show',['contact'=>$user,'position'=>Setup::readiness($user),'access'=>Setup::access($user),'invitation'=>Setup::invitation($user)]);}

 public function update(Request $r,User $user) {$this->manager($r);$d=$r->validate(['record_version'=>['required','integer','min:1'],'name'=>['required','string','max:150','regex:/\S/u'],'email'=>['required','email','max:254'],'active'=>['required','boolean'],'reason'=>['required','string','max:2000'],'confirmed'=>['accepted']]);Setup::updateContact($r->user(),(int)$r->session()->get('auth_version'),$user,(int)$d['record_version'],$d);return back()->with('status','Contact checked. Any saved identity/status change ended old sessions/invitations and revoked current apprentice permissions; verify each named authority again.');}
 public function revoke(Request $r,User $user) {$this->manager($r);$d=$r->validate(['record_version'=>['required','integer','min:1'],'stamp'=>['required','regex:/^[a-f0-9]{64}$/D'],'confirmed'=>['accepted']]);Setup::revokeInvitation($r->user(),(int)$r->session()->get('auth_version'),$user,(int)$d['record_version'],$d['stamp']);return back()->with('status','Outstanding employer invitation revoked. Existing workplace permissions were not changed.');}
 public function start(Request $r) {$u=$r->user();$scope=Setup::scope($u);return $this->view('start',['scope'=>$scope,'checks'=>Setup::checks($u,$scope)]);}
 public function check(Request $r,string $item) {abort_unless($r->user()->active&&$r->user()->role==='employer',403);$d=$r->validate(['version'=>['required','integer','min:0'],'scope_hash'=>['required','regex:/^[a-f0-9]{64}$/D'],'confirmed'=>['required','boolean']]);Setup::saveCheck($r->user(),(int)$r->session()->get('auth_version'),$item,(int)$d['version'],$d['scope_hash'],$r->boolean('confirmed'));return back()->with('status','Getting-started checklist updated. No review, workplace training, support permission or action was completed.');}
}
