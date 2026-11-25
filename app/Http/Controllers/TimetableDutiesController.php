<?php
namespace App\Http\Controllers;
use App\Models\{User,TutorGroup,TimetableGroupBrief as Brief,TimetableFlexibleDay as Day,TimetablePlanEntry as Entry,TimetablePlan as Plan,TimetableCoverAllocation as Cover};
use App\Support\{CentrePlanning as P,TimetableDuties as D,TimetableCover as C,ReviewedImports};
use App\Models\TrainingSession;
use App\Support\LearningAccess;
use App\Support\RegisterFollowup;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
class TimetableDutiesController extends Controller {
 private function allowed(Request $r):void {abort_unless(P::allowed($r->user()),403);}
 private function auth(Request $r):int {return (int)$r->session()->get('auth_version');}
 private function view(string $v,array $d=[]) {return response()->view('centre-planning.'.$v,$d)->header('Cache-Control','no-store, private')->header('Referrer-Policy','no-referrer');}
 private function people() {return User::orderBy('name')->get()->filter(fn($u)=>P::staff($u));}
 public function groups(Request $r) {$this->allowed($r);return $this->view('group-briefs',['groups'=>TutorGroup::orderBy('name')->get(),'briefs'=>Brief::get()->keyBy('group_id')]);}
 public function brief(Request $r,TutorGroup $group) {$this->allowed($r);$d=$r->validate(['version'=>['required','integer','min:0'],'start_date'=>['nullable','date_format:Y-m-d'],'notes'=>['nullable','string','max:1000'],'confirmed'=>['accepted']]);D::brief($r->user(),$this->auth($r),$group,(int)$d['version'],$d['start_date']??null,$d['notes']??'');return back()->with('status','Shared group planning details saved. Current membership/counts stay automatic; no learner records changed.');}
 public function flexible(Request $r) {$this->allowed($r);$d=$r->validate(['staff_id'=>['nullable','integer','min:1'],'date'=>['nullable','date_format:Y-m-d'],'weeks'=>['nullable','integer','between:1,26']]);$people=$this->people();$staff=isset($d['staff_id'])?$people->firstWhere('id',(int)$d['staff_id']):$people->first();abort_unless($staff,404);$first=$d['date']??today()->addDay()->toDateString();$weeks=(int)($d['weeks']??1);$dates=P::dates($first,$weeks);return $this->view('flexible',['people'=>$people,'staff'=>$staff,'owners'=>$people->filter(fn($u)=>P::allowed($u)),'first'=>$first,'weeks'=>$weeks,'dates'=>$dates,'preview'=>D::preview($staff,$dates),'days'=>Day::where('staff_id',$staff->id)->orderByDesc('work_date')->limit(100)->get()]);}
 public function saveDays(Request $r) {$this->allowed($r);$d=$r->validate(['staff_id'=>['required','integer','min:1'],'owner_id'=>['required','integer','min:1'],'date'=>['required','date_format:Y-m-d'],'weeks'=>['required','integer','between:1,26'],'start'=>['required','date_format:H:i'],'end'=>['required','date_format:H:i'],'source_hash'=>['required','regex:/^[a-f0-9]{64}$/D'],'confirmed'=>['accepted']]);D::saveDays($r->user(),$this->auth($r),User::findOrFail($d['staff_id']),User::findOrFail($d['owner_id']),P::dates($d['date'],(int)$d['weeks']),$d['source_hash'],$d['start'],$d['end']);return to_route('timetable-duties.flexible',collect($d)->only(['staff_id','date','weeks'])->all())->with('status','Flexible Duties Day classifications saved; existing work and working windows remain unchanged. Allocate work using the normal planning form or checked cover.');}
 public function withdrawDay(Request $r,Day $day) {$this->allowed($r);$d=$r->validate(['version'=>['required','integer','min:1'],'confirmed'=>['accepted']]);D::withdrawDay($r->user(),$this->auth($r),$day,(int)$d['version']);return back()->with('status','Flexible-day classification withdrawn; booked work and cover were not cancelled and must be checked separately.');}
 public function covers(Request $r) {$this->allowed($r);$filter=$r->validate(['page'=>['nullable','integer','min:1'],'date'=>['nullable','date_format:Y-m-d']]);$date=$filter['date']??null;return $this->view('covers',['date'=>$date,'covers'=>Cover::orderByDesc('updated_at')->paginate(25),'entries'=>Entry::where('kind','Teaching')->whereDate('work_date','>=',today())->when($date,fn($q)=>$q->whereDate('work_date',$date))->orderBy('work_date')->limit(500)->get()->filter(fn($e)=>($p=Plan::find($e->plan_id))&&P::published($p)&&$p->state==='Published'),'days'=>Day::where('state','Active')->whereDate('work_date','>=',today())->when($date,fn($q)=>$q->whereDate('work_date',$date))->orderBy('work_date')->get()->filter(fn($d)=>D::dayCurrent($d)&&($candidate=User::find($d->staff_id))&&$candidate->role==='tutor'),'token'=>(string)Str::uuid()]);}
 public function prepare(Request $r) {$this->allowed($r);$d=$r->validate(['entry_id'=>['required','integer','min:1'],'day_id'=>['required','integer','min:1'],'request_token'=>['required','uuid'],'reference'=>['required','string','max:1000'],'suitability_confirmed'=>['accepted'],'confirmed'=>['accepted']]);$c=C::prepare($r->user(),$this->auth($r),Entry::findOrFail($d['entry_id']),Day::findOrFail($d['day_id']),$d['request_token'],$d['reference']);return to_route('timetable-duties.cover',$c)->with('status','Cover proposal prepared. Check conflicts and publish explicitly; no register or apprentice access granted.');}
 public function cover(Request $request, Cover $cover)
 {
     $this->allowed($request);
     abort_unless(C::intact($cover), 409, 'Cover evidence needs repair.');

     $basis = $cover->source['basis'];
     $current = C::current($cover);
     $register = $current && isset($basis['register']['id'])
         ? TrainingSession::find($basis['register']['id'])
         : null;
     if ($register && ! RegisterFollowup::canOpen($request->user(), $register)) {
         $register = null;
     }

     return $this->view('cover', [
         'c' => $cover, 'basis' => $basis, 'checks' => C::inspect($cover), 'current' => $current,
         'register' => $register, 'canOpenGroupRegisters' => LearningAccess::manager($request->user()),
     ]);
 }
 public function decide(Request $r,Cover $cover) {$this->allowed($r);$d=$r->validate(['version'=>['required','integer','min:1'],'action'=>['required','in:check,publish,withdraw'],'reason'=>['nullable','string','max:2000'],'confirmed'=>['accepted']]);match($d['action']){'check'=>C::check($r->user(),$this->auth($r),$cover,(int)$d['version']),'publish'=>C::publish($r->user(),$this->auth($r),$cover,(int)$d['version']),'withdraw'=>C::withdraw($r->user(),$this->auth($r),$cover,(int)$d['version'],$d['reason']??'')};return back()->with('status','Cover planning decision recorded. Permanent Tutor, register permissions, attendance and OTJ remain unchanged.');}
}
