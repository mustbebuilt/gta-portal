<?php
namespace App\Http\Controllers;
use App\Models\RestrictionRecoveryRun as Run;
use App\Support\{PortalActions,LearningAccess,RestrictionRecovery as R};
use Illuminate\Http\Request;
use Illuminate\Support\Str;
class RestrictionRecoveryController extends Controller
{
 private function actor(Request $r){$u=PortalActions::actor($r->user());abort_unless(LearningAccess::manager($u),403);return $u;}
 private function view($name,$data){return response()->view('restriction-recovery.'.$name,$data)->header('Cache-Control','private, no-store')->header('Referrer-Policy','no-referrer');}
 public function index(Request $r){$this->actor($r);return $this->view('index',['runs'=>Run::orderByDesc('created_at')->paginate(20)]);}
 public function preview(Request $r){$u=$this->actor($r);$r->validate(['ledger'=>'required|file|max:1024']);$sealed=file_get_contents($r->file('ledger')->getRealPath());$preview=R::preview($u,$sealed);return $this->view('preview',compact('preview','sealed')+['token'=>(string)Str::uuid()]);}
 public function prepare(Request $r){$u=$this->actor($r);$d=$r->validate(['sealed'=>'required|string|max:1048576','source_hash'=>'required|string|size:64','selected'=>'required|array|min:1|max:25','selected.*'=>'required|integer|distinct|min:1','request_token'=>'required|uuid','review_on'=>'required|date_format:Y-m-d|after:today','retention'=>'required|string|min:5|max:1000','handover'=>'required|string|min:5|max:1000','reason'=>'required|string|min:5|max:1000','confirmed'=>'accepted']);$run=R::prepare($u,(int)$r->session()->get('auth_version'),$d['sealed'],$d['source_hash'],$d['selected'],$d['request_token'],$d['review_on'],$d['retention'],$d['handover'],$d['reason']);return to_route('restriction-recovery.show',$run);}
 public function show(Request $r,Run $run){$this->actor($r);abort_unless(R::intact($run),409);return $this->view('show',compact('run'));}
 public function review(Request $r,Run $run){$this->actor($r);$d=$r->validate(['version'=>'required|integer|min:1','accept'=>'required|boolean','reference'=>'required|string|min:5|max:1000','override'=>'nullable|boolean','confirmed'=>'accepted']);R::review($r->user(),(int)$r->session()->get('auth_version'),$run,(int)$d['version'],(bool)$d['accept'],$d['reference'],(bool)($d['override']??false));return to_route('restriction-recovery.show',$run);}
 public function apply(Request $r,Run $run){$this->actor($r);$d=$r->validate(['version'=>'required|integer|min:1','confirmed'=>'accepted']);R::apply($r->user(),(int)$r->session()->get('auth_version'),$run,(int)$d['version']);return to_route('restriction-recovery.show',$run)->with('status','Reviewed missing restrictions reapplied. No account was reactivated and no apprentice evidence was deleted.');}
}
