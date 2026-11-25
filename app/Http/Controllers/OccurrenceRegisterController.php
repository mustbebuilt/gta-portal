<?php
namespace App\Http\Controllers;
use App\Models\TimetablePlanEntry;
use App\Support\{CentrePlanning,OccurrenceRegisters};
use Illuminate\Http\Request;
class OccurrenceRegisterController extends Controller {
 private function allowed(Request $r):void {abort_unless(CentrePlanning::allowed($r->user()),403);}
 public function create(Request $r,TimetablePlanEntry $entry){$this->allowed($r);$d=$r->validate(['version'=>'required|integer|min:1','planned_learning'=>'required|string|max:10000','breaks_checked'=>'accepted','breaks'=>'nullable|array|max:10','breaks.*.start'=>'nullable|required_with:breaks.*.end|date_format:H:i','breaks.*.end'=>'nullable|required_with:breaks.*.start|date_format:H:i','confirmed'=>'accepted']);$breaks=array_values(array_filter($d['breaks']??[],fn($b)=>filled($b['start']??null)||filled($b['end']??null)));$session=OccurrenceRegisters::create($r->user(),(int)$r->session()->get('auth_version'),$entry,(int)$d['version'],$d['planned_learning'],$breaks);return to_route('attendance.show',$session)->with('status','Register created from the timetable.');}
 public function reconcile(Request $r,TimetablePlanEntry $entry){$this->allowed($r);$d=$r->validate(['version'=>'required|integer|min:1','link_hash'=>'required|regex:/^[a-f0-9]{64}$/D','delivery_version'=>'required|integer|min:1','source_hash'=>'required|regex:/^[a-f0-9]{64}$/D','reason'=>'required|string|max:2000','confirmed'=>'accepted']);OccurrenceRegisters::reconcile($r->user(),(int)$r->session()->get('auth_version'),$entry,(int)$d['version'],$d['link_hash'],(int)$d['delivery_version'],$d['source_hash'],$d['reason']);return back()->with('status','Delivered register reconciled to its saved planning occurrence; canonical attendance/OTJ remain unchanged.');}
}
