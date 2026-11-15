<?php
namespace App\Http\Controllers;
use App\Models\{Apprentice,DestinationContact as Contact,DestinationProposal as Proposal,ProgrammeOutcome};
use App\Support\{LeaverDestinations as Destinations,LearningAccess};
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LeaverDestinationController extends Controller {
 private function privateView(string $view,array $data=[]) {return response()->view('destinations.'.$view,$data)->header('Cache-Control','no-store, private')->header('Referrer-Policy','no-referrer');}
 private function matches(Request $r):array {
  abort_unless(Destinations::allowed($r->user()),403);
  $d=$r->validate(['search'=>['nullable','string','max:120'],'show'=>['nullable','in:all,source,unknown,reported,pending,overdue,today,assignments'],'end_from'=>['nullable','date_format:Y-m-d','before_or_equal:today'],'end_to'=>['nullable','date_format:Y-m-d','before_or_equal:today'],'page'=>['nullable','integer','min:1']]);
  if(!empty($d['end_from'])&&!empty($d['end_to'])&&$d['end_to']<$d['end_from']){throw ValidationException::withMessages(['end_to'=>'End-date range is reversed.']);}
  $rows=Destinations::rows($r->user());$search=trim($d['search']??'');
  $rows=$rows->filter(function($row)use($d,$search){$a=$row['apprentice'];$end=$row['outcome']->context['endDate']??'';return (!$search||Str::contains(Str::lower($a->first_name.' '.$a->last_name.' '.$a->reference),Str::lower($search)))&& (empty($d['end_from'])||$end>=$d['end_from'])&&(empty($d['end_to'])||($end!==''&&$end<=$d['end_to']));})->values();
  $counts=['all'=>$rows->count()];foreach(['source','unknown','reported','pending','overdue','today','assignments']as$key){$counts[$key]=$rows->filter(fn($row)=>(bool)$row['flags'][$key])->count();}
  $summary=['all'=>$rows->count(),'verified'=>$rows->where('basis','Verified')->count(),'reported'=>$rows->where('basis','Reported')->count(),'unknown'=>$rows->where('basis','Unknown')->count(),'checked'=>$rows->where('checked',true)->count()];
  $show=$d['show']??'all';return ['rows'=>$rows->filter(fn($row)=>$show==='all'||$row['flags'][$show])->values(),'counts'=>$counts,'summary'=>$summary,'search'=>$search,'show'=>$show];
 }
 public function index(Request $r) {
  $data=$this->matches($r);$page=(int)$r->query('page',1);$data['items']=new LengthAwarePaginator($data['rows']->forPage($page,20),$data['rows']->count(),20,$page,['path'=>route('destinations.index'),'query'=>$r->except('page')]);
  return $this->privateView('index',$data);
 }
 public function csv(Request $r) {
  $data=$this->matches($r);$rows=$data['rows'];return response()->streamDownload(function()use($rows):void {
   $f=fopen('php://output','w');fwrite($f,"\xEF\xBB\xBF");$write=fn($values)=>fputcsv($f,array_map([Destinations::class,'csvCell'],$values),',','"','');
   $write(['Apprentice reference','First name','Last name','Recorded programme status','Programme end date','Destination at recorded date','Destination basis','Destination reference date','Current independently checked proposal','Careers owner','Last contact date','Contact result','Next follow-up date','Pending proposals','Needs source check']);
   foreach($rows as$row){$a=$row['apprentice'];$c=$row['outcome']->context;$write([$a->reference,$a->first_name,$a->last_name,$c['status']??'Unknown',$c['endDate']??'',$row['destination'],$row['basis'],$row['destination_date'],$row['checked']?'Yes':'No',$row['owner'],$row['last_contact'],$row['contact_result'],$row['next'],$row['pending'],$row['flags']['source']?'Yes':'No']);}fclose($f);
  },'GTA-Leaver-Destination-Followup.csv',['Content-Type'=>'text/csv; charset=UTF-8','Cache-Control'=>'no-store, private','Referrer-Policy'=>'no-referrer']);
 }
 public function show(Request $r,Apprentice $apprentice) {
  abort_unless(Destinations::can($r->user(),$apprentice),403);$r->validate(['contact_page'=>['nullable','integer','min:1'],'proposal_page'=>['nullable','integer','min:1']]);$outcome=ProgrammeOutcome::where('apprentice_id',$apprentice->id)->first();
  $contacts=Contact::where('apprentice_id',$apprentice->id)->orderByDesc('contacted_on')->orderByDesc('id')->paginate(10,['*'],'contact_page');$contactDetails=[];
  foreach($contacts as$c){try{$detail=$c->detail;$contactDetails[$c->id]=['note'=>$detail['note']??'','reference'=>$detail['reference']??'Not recorded','void_reason'=>$c->void_reason];}catch(\Throwable){$contactDetails[$c->id]=['note'=>'Contact detail cannot be verified. Ask GTA to check it.','reference'=>'Unavailable','void_reason'=>'Unavailable'];}}
  return $this->privateView('show',['contactDetails'=>$contactDetails,'apprentice'=>$apprentice,'outcome'=>$outcome,'anchor'=>Destinations::anchor($apprentice,$outcome),'contacts'=>$contacts,'proposals'=>Proposal::where('apprentice_id',$apprentice->id)->orderByDesc('created_at')->paginate(10,['*'],'proposal_page'),'choices'=>Destinations::choices(),'contactToken'=>(string)Str::uuid(),'proposalToken'=>(string)Str::uuid(),'manager'=>LearningAccess::manager($r->user())]);
 }
 public function contact(Request $r,Apprentice $apprentice) {
  abort_unless(Destinations::can($r->user(),$apprentice),403);$d=$r->validate(['request_token'=>['required','uuid'],'outcome_version'=>['required','integer','min:1'],'contacted_on'=>['required','date_format:Y-m-d','before_or_equal:today'],'method'=>['required',Rule::in(Destinations::METHODS)],'result'=>['required',Rule::in(Destinations::RESULTS)],'next_followup_on'=>['nullable','date_format:Y-m-d','after_or_equal:today'],'reference'=>['required','string','max:1000'],'note'=>['nullable','string','max:4000'],'confirmed'=>['accepted']]);
  Destinations::recordContact($r->user(),(int)$r->session()->get('auth_version'),$apprentice,$d);return back()->with('status','Dated contact recorded. No message was sent and no programme status or destination was changed.');
 }
 public function voidContact(Request $r,Contact $contact) {
  $d=$r->validate(['version'=>['required','integer','min:1'],'reason'=>['required','string','max:1000'],'confirmed'=>['accepted']]);Destinations::voidContact($r->user(),(int)$r->session()->get('auth_version'),$contact,(int)$d['version'],$d['reason']);return back()->with('status','Contact voided with history retained. Follow-up uses the latest remaining valid contact.');
 }
 public function propose(Request $r,Apprentice $apprentice) {
  abort_unless(Destinations::can($r->user(),$apprentice),403);$choices=Destinations::choices();$d=$r->validate(['request_token'=>['required','uuid'],'outcome_version'=>['required','integer','min:1'],'destination'=>['required',Rule::in(array_diff($choices['destination'],['Unknown']))],'basis'=>['required','in:Reported,Verified'],'observed_on'=>['required','date_format:Y-m-d','before_or_equal:today'],'reference'=>['required','string','max:1000'],'confirmed'=>['accepted']]);
  $x=Destinations::propose($r->user(),(int)$r->session()->get('auth_version'),$apprentice,$d);return to_route('destinations.proposal',$x)->with('status','Private destination proposal prepared. A different administrator/CEO must check the source before reporting context changes.');
 }
 public function proposal(Request $r,Proposal $proposal) {
  $a=Apprentice::findOrFail($proposal->apprentice_id);abort_unless(Destinations::can($r->user(),$a),403);
  try{$payload=$proposal->payload;$source=$proposal->source;$note=$proposal->review_note;$readable=\App\Support\ReviewedImports::hash($payload)===$proposal->payload_hash&&\App\Support\ReviewedImports::hash($source)===$proposal->source_hash;}catch(\Throwable){$readable=false;}
  abort_unless($readable,409,'This proposal cannot be verified. Ask GTA to check it.');
  return $this->privateView('proposal',['proposal'=>$proposal,'apprentice'=>$a,'payload'=>$payload,'source'=>$source,'reviewNote'=>$note,'manager'=>LearningAccess::manager($r->user()),'current'=>Destinations::proposalCurrent($proposal),'appliedCurrent'=>Destinations::appliedCurrent($proposal),'events'=>DB::table('destination_events')->where('proposal_id',$proposal->id)->orderBy('id')->get()]);
 }
 public function review(Request $r,Proposal $proposal) {
  $d=$r->validate(['version'=>['required','integer','min:1'],'decision'=>['required','in:apply,return'],'note'=>['required','string','max:1000'],'confirmed'=>['accepted']]);Destinations::review($r->user(),(int)$r->session()->get('auth_version'),$proposal,(int)$d['version'],$d['decision'],$d['note']);return back()->with('status',$d['decision']==='apply'?'Checked destination applied to the four internal reporting fields only. Live ILR/achievement/training were not changed.':'Proposal returned without changing reporting context. Prepare a new proposal when corrected.');
 }
 public function withdraw(Request $r,Proposal $proposal) {
  $d=$r->validate(['version'=>['required','integer','min:1'],'reason'=>['required','string','max:1000'],'confirmed'=>['accepted']]);Destinations::withdraw($r->user(),(int)$r->session()->get('auth_version'),$proposal,(int)$d['version'],$d['reason']);return back()->with('status','Proposal withdrawn. An applied correction restored only its original destination fields after checking no later context changes. History is retained.');
 }
}
