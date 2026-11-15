<?php
namespace App\Http\Controllers;
use App\Models\TimetablePlan as Plan;
use App\Support\{PortalActions,CentrePlanning,TimetableWorkMoves};
use Illuminate\Http\Request;
use Illuminate\Support\Str;
class TimetableWorkMovesController extends Controller
{
 public function index(Request $r){$u=PortalActions::actor($r->user());abort_unless(CentrePlanning::allowed($u),403);$plans=Plan::where('state','Published')->orderByDesc('updated_at')->limit(201)->get();$more=$plans->count()>200;$plans=$plans->take(200)->filter(function($p){try{return count($p->source['data']['rows'])===1&&in_array($p->source['data']['args']['kind'],TimetableWorkMoves::KINDS,true)&&\Carbon\Carbon::parse($p->source['data']['args']['date'].' '.$p->source['data']['args']['start'])->gt(now());}catch(\Throwable){return false;}});return response()->view('work-moves.index',compact('plans','more'))->header('Cache-Control','private, no-store')->header('Referrer-Policy','no-referrer');}
 public function show(Request $r,Plan $plan){$preview=TimetableWorkMoves::preview($r->user(),$plan);$token=(string)Str::uuid();return response()->view('work-moves.show',compact('preview','token'))->header('Cache-Control','private, no-store')->header('Referrer-Policy','no-referrer');}
 public function apply(Request $r,Plan $plan){$d=$r->validate(['version'=>'required|integer|min:1','source_hash'=>'required|string|size:64','choice'=>'required|string|max:30','request_token'=>'required|uuid','reason'=>'required|string|min:5|max:1000','confirmed'=>'accepted','warnings_accepted'=>'nullable|boolean']);$new=TimetableWorkMoves::apply($r->user(),(int)$r->session()->get('auth_version'),$plan,(int)$d['version'],$d['source_hash'],$d['choice'],$d['request_token'],$d['reason'],(bool)($d['warnings_accepted']??false));return to_route('centre-planning.show',$new)->with('status','Work rescheduled through the checked planner. The original future reservation is retained as withdrawn history; no task, register or OTJ has been completed.');}
}
