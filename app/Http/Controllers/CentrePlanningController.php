<?php
namespace App\Http\Controllers;
use App\Models\{User,TutorGroup,TimetableRoom as Room,TimetablePlan as Plan,TimetablePlannerAccess as Access,TimetablePlanEntry as Entry,TrainingSession,TimetableDeliveryRecord};
use App\Support\{CentrePlanning as P,CentreTimetable as T,LearningAccess,OccurrenceRegisters,RegisterCreation,RegisterFollowup,ReviewedImports,WeeklyRegisters};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class CentrePlanningController extends Controller {
 private function allowed(Request $r):void {abort_unless(P::allowed($r->user()),403);}
 private function auth(Request $r):int {return (int)$r->session()->get('auth_version');}
 private function view(string $name,array $data=[]) {return response()->view('centre-planning.'.$name,$data)->header('Cache-Control','no-store, private')->header('Referrer-Policy','no-referrer');}
 private function staff() {return User::orderBy('name')->get()->filter(fn($u)=>P::staff($u));}
 private function boardData(Request $r):array {$d=$r->validate(['section'=>['nullable','in:all,teaching,other,review'],'scope'=>['nullable','in:own,staff,manage'],'layout'=>['nullable','in:day,timeline'],'week'=>['nullable','date_format:Y-m-d'],'mode'=>['nullable','in:staff,room,group'],'site'=>['nullable','in:Doncaster,Sheffield'],'resource'=>['nullable','integer','min:1'],'resource_mode'=>['nullable','in:staff,room,group']]);$scope=$d['scope']??(P::allowed($r->user())?'manage':'own');abort_if($scope==='manage'&&!P::allowed($r->user()),403);$own=$scope==='own';$b=T::board($r->user(),$d['week']??today()->toDateString(),$own?'staff':($d['mode']??'staff'),$own?null:($d['site']??null),$own?$r->user()->id:(isset($d['resource'])&&(!isset($d['resource_mode'])||$d['resource_mode']===($d['mode']??'staff'))?(int)$d['resource']:null),($d['layout']??'day')==='day',$scope==='staff');$b['scope']=$scope;$b['section']=$d['section']??'all';if($b['mode']==='staff'&&$b['section']!=='all'){$label=match($b['section']){'teaching'=>'Teaching tutors','other'=>'Other staff',default=>'Section needs review'};$b['resources']=array_values(array_filter($b['resources'],fn($x)=>$x['section']===$label));$ids=array_column($b['resources'],'id');$b['rows']=array_values(array_filter($b['rows'],fn($x)=>in_array($x['staff_id'],$ids,true)));}return $b;}
 public function index(Request $r)
 {
     $board = $this->boardData($r);
     $planned = collect($board['rows'])->filter(fn ($item) => $item['plan_id'] && $item['kind']==='Teaching' && !$item['stale']);
     $plans = Plan::whereIn('id', $planned->pluck('plan_id')->unique())->get()->keyBy('id');
     $entries = Entry::whereIn('plan_id', $plans->keys())
         ->whereIn(DB::raw('DATE(work_date)'), $board['dates'])->get()
         ->keyBy(fn (Entry $entry) => 'p'.$entry->plan_id.':'.$entry->work_date->toDateString());
     $nearby = OccurrenceRegisters::nearbyRegisters($entries);
     $groups = TutorGroup::whereIn('id', $entries->pluck('group_id'))->get()->keyBy('id');
     $board['registerLinks'] = [];
     $board['weeklyRegisterEntries'] = [];
     foreach ($planned as $item) {
         $entry = $entries->get($item['key']);
         if (!$entry) {
             continue;
         }
         $overlaps = OccurrenceRegisters::overlaps($entry, $nearby);
         $group = $groups->get($entry->group_id);
         $weekly = $group ? OccurrenceRegisters::weeklyDefaults($entry, $group) : null;
         $thisWeek = $weekly && $weekly['date'] === (WeeklyRegisters::thisWeek($group)['date'] ?? null);
         $session = OccurrenceRegisters::matchingRegister($r->user(), $entry, $plans[$entry->plan_id], $overlaps);
         if ($session) {
             $board['registerLinks'][$item['key']] = route('attendance.show', $session);
         }
         if ($thisWeek && ($session || ($overlaps->isEmpty() && !$entry->training_session_id && !$entry->link_proof
             && RegisterCreation::allowed($r->user(), $group)))) {
             $board['weeklyRegisterEntries'][$item['key']] = $weekly + ['group' => $group];
         }
     }

     return $this->view('board', $board);
 }
 public function export(Request $r) {$this->allowed($r);$b=$this->boardData($r);return response()->json(['week'=>$b['week'],'mode'=>$b['mode'],'site'=>$b['site'],'timezone'=>config('app.timezone'),'scale'=>$b['scale'],'resources'=>$b['resources'],'totals'=>$b['totals'],'rows'=>array_map(fn($x)=>collect($x)->only(['key','work_date','start_time','end_time','staff_id','room_id','group_id','kind','site','external','stale'])->all(),$b['rows']),'unresolved_sources'=>$b['uncertain'],'drafts_included'=>false,'actual_attendance_or_otj'=>false],200,['Content-Disposition'=>'attachment; filename="GTA-Planned-Timetable.json"','Cache-Control'=>'no-store, private','Referrer-Policy'=>'no-referrer']);}
 public function setup(Request $r) {$this->allowed($r);return $this->view('setup',['rooms'=>Room::orderBy('site')->orderBy('name')->get(),'staff'=>$this->staff(),'access'=>Access::get()->keyBy('user_id'),'manager'=>LearningAccess::manager($r->user())]);}
 public function room(Request $r,Room $room) {$this->allowed($r);$d=$r->validate(['version'=>['required','integer','min:1'],'capacity'=>['required','integer','between:1,200'],'uses'=>['nullable','array'],'uses.*'=>['in:Teaching,Review,Admin','distinct'],'active'=>['nullable','boolean'],'confirmed'=>['accepted']]);P::room($r->user(),$this->auth($r),$room,(int)$d['version'],['capacity'=>(int)$d['capacity'],'uses'=>$d['uses']??[],'active'=>(bool)($d['active']??false)]);return back()->with('status','Room capacity and suitable uses checked. Existing published planning stays reserved and is flagged if its source changed.');}
 public function grant(Request $r,User $user) {abort_unless(LearningAccess::manager($r->user()),403);$d=$r->validate(['version'=>['required','integer','min:0'],'active'=>['required','boolean'],'confirmed'=>['accepted']]);P::grant($r->user(),$this->auth($r),$user,(int)$d['version'],(bool)$d['active']);return back()->with('status','Centre-planning permission updated without changing the staff member’s portal role.');}
 private function windowData(Request $r):array {$this->allowed($r);$d=$r->validate(['staff_id'=>['nullable','integer','min:1'],'date'=>['nullable','date_format:Y-m-d'],'weeks'=>['nullable','integer','between:1,53']]);$staff=$this->staff();$selected=isset($d['staff_id'])?$staff->firstWhere('id',(int)$d['staff_id']):$staff->first();abort_unless($selected,404);$date=$d['date']??today()->addDay()->toDateString();$weeks=(int)($d['weeks']??1);$dates=P::dates($date,$weeks);$source=P::windowSource($selected,$dates);return compact('staff','selected','date','weeks','dates','source');}
 public function windows(Request $r) {return $this->view('windows',$this->windowData($r));}
 public function saveWindows(Request $r) {$this->allowed($r);$d=$r->validate(['staff_id'=>['required','integer','min:1'],'date'=>['required','date_format:Y-m-d'],'weeks'=>['required','integer','between:1,53'],'source_hash'=>['required','regex:/^[a-f0-9]{64}$/D'],'working'=>['required','boolean'],'start'=>['nullable','date_format:H:i'],'end'=>['nullable','date_format:H:i'],'confirmed'=>['accepted']]);P::windows($r->user(),$this->auth($r),User::findOrFail($d['staff_id']),P::dates($d['date'],(int)$d['weeks']),$d['source_hash'],(bool)$d['working'],$d['start']??null,$d['end']??null);return to_route('centre-planning.windows',collect($d)->only(['staff_id','date','weeks'])->all())->with('status','Exact dated working windows saved. This is planning availability, not a contract, attendance or absence record.');}
 public function plans(Request $r) {$this->allowed($r);$r->validate(['page'=>['nullable','integer','min:1']]);return $this->view('plans',['plans'=>Plan::orderByDesc('updated_at')->orderByDesc('id')->paginate(25)]);}
 public function form(Request $r,?Plan $plan=null) {$this->allowed($r);if($plan){abort_unless($plan->state==='Draft'&&P::intact($plan),409);}$prefill=$r->validate(['staff_id'=>'nullable|integer|min:1','date'=>'nullable|date_format:Y-m-d','kind'=>'nullable|in:'.implode(',',P::TYPES)]);$d=$plan?->source['data']['args']??array_filter($prefill,fn($v)=>$v!==null);return $this->view('form',['p'=>$plan,'d'=>$d,'staff'=>$this->staff(),'rooms'=>Room::orderBy('site')->orderBy('name')->get(),'groups'=>TutorGroup::where('active',true)->orderBy('name')->get(),'token'=>(string)Str::uuid()]);}
 public function save(Request $r,?Plan $plan=null) {$this->allowed($r);$d=$r->validate(['request_token'=>['required','uuid'],'version'=>['required','integer','min:0'],'staff_id'=>['required','integer','min:1'],'date'=>['required','date_format:Y-m-d'],'weeks'=>['required','integer','between:1,53'],'start'=>['required','date_format:H:i'],'end'=>['required','date_format:H:i'],'kind'=>['required','in:'.implode(',',P::TYPES)],'site'=>['required','in:'.implode(',',P::SITES)],'room_id'=>['nullable','integer','min:1'],'group_id'=>['nullable','integer','min:1'],'headcount'=>['nullable','integer','between:1,200'],'excluded_dates_text'=>['nullable','string','max:5000'],'title'=>['required','string','max:160'],'confirmed'=>['accepted']]);$d['excluded_dates']=preg_split('/[\s,]+/',trim($d['excluded_dates_text']??''),-1,PREG_SPLIT_NO_EMPTY);$p=P::save($r->user(),$this->auth($r),$plan,(int)$d['version'],$d['request_token'],$d);return to_route('centre-planning.show',$p)->with('status','Exact recurring dates saved as a draft. Check before publishing; no register or attendance created.');}
 public function show(Request $r, Plan $plan)
 {
     $this->allowed($r);
     abort_unless(P::intact($plan), 409, 'Planning evidence needs repair.');
     $current = P::fresh($plan);
     $published = P::published($plan);
     $actions = [];
     if ($published) {
         $dates = collect(P::liveRows($plan))->pluck('work_date');
         $entries = Entry::where('plan_id', $plan->id)->where('kind', 'Teaching')
             ->whereIn(DB::raw('DATE(work_date)'), $dates)->orderBy('work_date')->get();
         $groups = TutorGroup::whereIn('id', $entries->pluck('group_id')->unique())->get()->keyBy('id');
         $nearby = OccurrenceRegisters::nearbyRegisters($entries);
         $linked = TrainingSession::whereIn('id', $entries->pluck('training_session_id')->filter())->get()->keyBy('id');
         $receipts = TimetableDeliveryRecord::whereIn('training_session_id', $linked->keys())->get()->keyBy('training_session_id');
         $weeks = $entries->map(fn (Entry $entry) => $entry->work_date->copy()->startOfWeek()->toDateString())->unique();
         $regularRegisters = TrainingSession::whereIn('tutor_group_id', $groups->keys())
             ->whereIn('regular_week', $weeks)->get(['tutor_group_id', 'regular_week'])
             ->keyBy(fn (TrainingSession $record) => $record->tutor_group_id.':'.$record->regular_week);
         foreach ($entries as $entry) {
             $group = $groups->get($entry->group_id);
             $overlaps = OccurrenceRegisters::overlaps($entry, $nearby);
             $session = $current ? OccurrenceRegisters::matchingRegister($r->user(), $entry, $plan, $overlaps) : null;
             $future = $plan->state === 'Published' && \Carbon\Carbon::parse($entry->work_date->toDateString().' '.$entry->start_time, config('app.timezone'))->gt(now());
             $defaults = $group ? OccurrenceRegisters::weeklyDefaults($entry, $group) : null;
             $weeklyCollision = $defaults && $regularRegisters->has($entry->group_id.':'.$defaults['week']);
             $actions[$entry->work_date->toDateString()] = [
                 'entry' => $entry,
                 'session' => $session,
                 'overlaps' => $overlaps->filter(fn ($record) => RegisterFollowup::canOpen($r->user(), $record)),
                 'review' => !$session && (!$current || $overlaps->isNotEmpty() || $entry->link_proof || $entry->training_session_id || $weeklyCollision),
                 'canCreate' => $current && $future && $overlaps->isEmpty() && !$entry->link_proof && !$entry->training_session_id
                     && !$weeklyCollision && $group && RegisterCreation::allowed($r->user(), $group),
                 'canLink' => $session && $future && !$entry->link_proof && !$session->marks_exists && !filled($session->delivered_learning),
                 'needsLinkReview' => $entry->link_proof && !P::linkCurrent($entry, $plan),
                 'defaults' => $defaults['settings'] ?? null,
                 'linked' => $linked->get($entry->training_session_id),
                 'receipt' => $receipts->get($entry->training_session_id),
             ];
         }
     }

     return $this->view('show', [
         'p' => $plan, 'source' => $plan->source, 'check' => P::inspect($plan),
         'current' => $current, 'registerActions' => $actions,
     ]);
 }
 public function decide(Request $r,Plan $plan) {$this->allowed($r);$d=$r->validate(['version'=>['required','integer','min:1'],'action'=>['required','in:check,publish,withdraw'],'warnings_accepted'=>['nullable','boolean'],'reason'=>['nullable','string','max:2000'],'confirmed'=>['accepted']]);match($d['action']){'check'=>P::check($r->user(),$this->auth($r),$plan,(int)$d['version'],(bool)($d['warnings_accepted']??false)),'publish'=>P::publish($r->user(),$this->auth($r),$plan,(int)$d['version']),'withdraw'=>P::withdraw($r->user(),$this->auth($r),$plan,(int)$d['version'],$d['reason']??'')};return back()->with('status','Planning decision recorded. Actual registers, attendance, OTJ, review completion and external messages remain unchanged.');}
 public function link(Request $r,Entry $entry) {$this->allowed($r);$d=$r->validate(['version'=>['required','integer','min:1'],'session_id'=>['required','integer','min:1'],'session_version'=>['required','integer','min:1'],'confirmed'=>['accepted']]);P::link($r->user(),$this->auth($r),$entry,(int)$d['version'],TrainingSession::findOrFail($d['session_id']),(int)$d['session_version']);return back()->with('status','Exact existing register linked; room reservation retained and duplicate grid item collapsed. No attendance or hours changed.');}
 public function unlink(Request $r,Entry $entry) {$this->allowed($r);$d=$r->validate(['version'=>['required','integer','min:1'],'link_hash'=>['required','regex:/^[a-f0-9]{64}$/D'],'confirmed'=>['accepted']]);P::unlink($r->user(),$this->auth($r),$entry,(int)$d['version'],$d['link_hash']);return back()->with('status','Planning link removed. The actual register remains unchanged; reconcile any unlinked duplicate before rebooking.');}

}
