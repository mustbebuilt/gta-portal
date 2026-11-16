<?php
namespace App\Http\Controllers;
use App\Models\{Apprentice,CentreManagerFollowup as Work};
use App\Support\{PortalActions,CentreFollowups as F,CentreOversight as O};
use Illuminate\Http\Request;
class CentreFollowupsController extends Controller {
 private function view(string $name,array $data=[]){return response()->view('centre-followups.'.$name,$data)->header('Cache-Control','private, no-store')->header('Referrer-Policy','no-referrer');}
 public function index(Request $r){$u=PortalActions::actor($r->user());abort_unless(F::inbox($u),403);$d=$r->validate(['state'=>'nullable|in:Open,In progress,Waiting,Resolved,Cancelled','page'=>'nullable|integer|min:1']);$query=F::query($u);if($d['state']??null){$query->where('status',$d['state']);}$work=$query->with(['apprentice','owner'])->orderBy('due_on')->orderBy('id')->paginate(25)->withQueryString();return $this->view('index',compact('u','work'));}
 public function show(Request $r,Work $work){$u=PortalActions::actor($r->user());abort_unless(F::visible($u,$work),404);abort_unless(F::intact($work),409,'Follow-up integrity needs investigation.');$a=Apprentice::findOrFail($work->apprentice_id);$isOwner=F::owner($u,$work,$a);$isManager=F::manager($u,$a,$work->area);$position=$isOwner?O::position($u,$a,$work->area,...$work->details['initial']['period']):null;return $this->view('show',compact('u','work','a','isOwner','isManager','position'));}
 public function update(Request $r,Work $work){$u=PortalActions::actor($r->user());abort_unless(F::visible($u,$work),404);$d=$r->validate(['version'=>'required|integer|min:1','status'=>'required|in:In progress,Waiting,Resolved,Cancelled','source_hash'=>'required|regex:/^[a-f0-9]{64}$/D','reference'=>'required|string|min:5|max:1000','confirmed'=>'accepted']);F::update($r->user(),(int)$r->session()->get('auth_version'),$work,(int)$d['version'],$d['status'],$d['source_hash'],$d['reference']);return to_route('centre-followups.show',$work)->with('status','Follow-up decision recorded. Tasks, registers, OTJ, qualifications and reviews retain their original completion workflows.');}
}
