<?php
namespace App\Http\Controllers;
use App\Models\Apprentice;
use App\Support\ApprenticeAlerts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class ApprenticeAlertController extends Controller {
 private function profile(Request $r,bool $lock=false):?Apprentice {
  abort_unless($r->user()->active&&$r->user()->role==='apprentice',403);
  $rows=Apprentice::where('user_id',$r->user()->id)->when($lock,fn($q)=>$q->lockForUpdate())->get();
  return $rows->count()===1?$rows->first():null;
 }
 public function index(Request $r) {
  $a=$this->profile($r);return response()->view('account.alerts',['apprentice'=>$a],$a?200:409)->header('Cache-Control','no-store, private');
 }
 public function save(Request $r) {
  abort_unless($r->user()->role==='apprentice',403);if(is_string($r->input('alert_mobile'))){$r->merge(['alert_mobile'=>ApprenticeAlerts::normalise($r->input('alert_mobile'))]);}
  $data=$r->validate(ApprenticeAlerts::rules());
  DB::transaction(function()use($r,$data):void {
   $a=$this->profile($r,true);abort_unless($a,409);
   if(ApprenticeAlerts::apply($a,$r->user(),$r->session()->get('auth_version'),$data)){$a->version++;$a->save();DB::table('audit_events')->insert(['user_id'=>$r->user()->id,'apprentice_id'=>$a->id,'event'=>'alert_preferences_changed','created_at'=>now()]);}
  });return to_route('alerts.index')->with('status','Your alert preferences have been saved. Message delivery depends on the connected services.');
 }
}
