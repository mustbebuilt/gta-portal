<?php
namespace App\Http\Controllers;
use App\Models\{Apprentice,ApprenticeAlertDelivery as Delivery,AlertReceiptBatch as Batch,AlertReceiptRecord as Receipt,AlertFollowupCase as Followup,User};
use App\Support\{AlertReceiptOperations as Ops,LearningAccess,LeaverDestinations};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt,DB};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AlertReceiptOperationsController extends Controller {
 private function manager(Request $r):void {abort_unless(Ops::allowed($r->user()),403);}
 private function privateView(string $name,array $data=[]) {return response()->view('receipt-operations.'.$name,$data)->header('Cache-Control','no-store, private')->header('Referrer-Policy','no-referrer');}
 private function query(Request $r) {
  $this->manager($r);$d=$r->validate(['search'=>['nullable','string','max:120'],'channel'=>['nullable','in:all,email,sms'],'show'=>['nullable','in:all,with_evidence,no_evidence,open_cases,awaiting_check,overdue,closed_cases,transport_check'],'from'=>['nullable','date_format:Y-m-d'],'to'=>['nullable','date_format:Y-m-d','before_or_equal:today'],'page'=>['nullable','integer','min:1']]);
  $from=$d['from']??today()->subDays(7)->toDateString();$to=$d['to']??today()->toDateString();if($from>$to){throw ValidationException::withMessages(['to'=>'Date range is reversed.']);}
  $search=trim($d['search']??'');$channel=$d['channel']??'all';$show=$d['show']??'all';
  $q=Delivery::whereDate('cycle_date','>=',$from)->whereDate('cycle_date','<=',$to)->when($channel!=='all',fn($q)=>$q->where('channel',$channel));
  if($search!==''){$q->where(function($q)use($search){$q->where('id',$search)->orWhereIn('apprentice_id',Apprentice::where(function($a)use($search){$a->where('reference','like','%'.$search.'%')->orWhere('first_name','like','%'.$search.'%')->orWhere('last_name','like','%'.$search.'%');})->select('id'));});}
  $evidence=function($query){$query->selectRaw('1')->from('alert_receipt_records as rr')->whereColumn('rr.delivery_id','apprentice_alert_deliveries.id')->whereNull('rr.withdrawn_at')->whereIn('rr.batch_id',Batch::where('state','Applied')->select('id'));};
  if($show==='with_evidence'){$q->whereExists($evidence);}if($show==='no_evidence'){$q->whereNotExists($evidence);}
  $caseQuery=function($query)use($show){$query->selectRaw('1')->from('alert_followup_cases as fc')->whereColumn('fc.delivery_id','apprentice_alert_deliveries.id');if($show==='open_cases'){$query->where('fc.state','Open');}if($show==='awaiting_check'){$query->where('fc.state','Awaiting check');}if($show==='closed_cases'){$query->where('fc.state','Closed');}if($show==='overdue'){$query->whereIn('fc.state',['Open','Awaiting check'])->whereDate('fc.due_on','<',today());}};
  if(in_array($show,['open_cases','awaiting_check','overdue','closed_cases'],true)){$q->whereExists($caseQuery);}if($show==='transport_check'){$q->whereIn('state',['Rejected','Uncertain','Dispatching']);}
  return ['query'=>$q,'from'=>$from,'to'=>$to,'search'=>$search,'channel'=>$channel,'show'=>$show];
 }
 public function index(Request $r) {
  $data=$this->query($r);$q=$data['query'];$data['counts']=(clone $q)->select('state',DB::raw('COUNT(*) as total'))->groupBy('state')->pluck('total','state')->all();$data['total']=(clone $q)->count();
  $items=$q->orderByDesc('cycle_date')->orderByDesc('id')->paginate(20)->withQueryString();$positions=[];foreach($items as$d){$positions[$d->id]=Ops::position($d);}
  $data+=['items'=>$items,'positions'=>$positions,'people'=>Apprentice::whereIn('id',$items->pluck('apprentice_id'))->get(['id','reference','first_name','last_name'])->keyBy('id'),'batches'=>Batch::orderByDesc('created_at')->orderByDesc('id')->paginate(10,['*'],'batch_page'),'requestToken'=>(string)Str::uuid()];unset($data['query']);return $this->privateView('index',$data);
 }
 public function template(Request $r) {
  $this->manager($r);return response(implode(',',Ops::HEADERS)."\n",200,['Content-Type'=>'text/csv; charset=UTF-8','Content-Disposition'=>'attachment; filename="GTA-Receipt-Import-Template.csv"','Cache-Control'=>'no-store, private']);
 }
 public function csv(Request $r) {
  $data=$this->query($r);$q=$data['query'];if((clone $q)->count()>1000){throw ValidationException::withMessages(['receipts'=>'Export is limited to 1,000 matching attempts. Narrow the dates or filters; no partial file was exported.']);}
  $rows=$q->orderBy('cycle_date')->orderBy('id')->get();return response()->streamDownload(function()use($rows):void {
   $f=fopen('php://output','w');fwrite($f,"\xEF\xBB\xBF");$write=fn($v)=>fputcsv($f,array_map([LeaverDestinations::class,'csvCell'],$v),',','"','');
   $write(['Delivery ID','Apprentice reference','Channel','Cycle date','Transport attempt state','Stored provider reference (email is hash)','Recorded provider outcome','Evidence event UTC','Conflict resolved by independent check','Follow-up state','Owner account ID','Due date']);
   $people=Apprentice::whereIn('id',$rows->pluck('apprentice_id'))->pluck('reference','id');foreach($rows as$d){$p=Ops::position($d);$c=$p['case'];$write([$d->id,$people[$d->apprentice_id]??'Not available',$d->channel,$d->cycle_date->toDateString(),$d->state,$d->provider_id,$p['outcome'],$p['record']?->occurred_at->utc()->format('Y-m-d H:i:s'),$p['resolved']?'Yes':'No',$c?->state??'Not opened',$c?->owner_id,$c?->due_on?->toDateString()]);}fclose($f);
  },'GTA-Reminder-Delivery-Operations.csv',['Content-Type'=>'text/csv; charset=UTF-8','Cache-Control'=>'no-store, private','Referrer-Policy'=>'no-referrer']);
 }
 public function preview(Request $r) {
  $this->manager($r);$d=$r->validate(['request_token'=>['required','uuid'],'csv'=>['required','string','max:250000'],'source_reference'=>['required','string','max:1000'],'confirmed'=>['accepted']]);$b=Ops::preview($r->user(),(int)$r->session()->get('auth_version'),$d['request_token'],$d['csv'],$d['source_reference']);return to_route('receipt-operations.batch',$b)->with('status','Encrypted preview saved. Nothing was sent or applied. A different administrator/CEO must check the cited provider source.');
 }
 public function batch(Request $r,Batch $batch) {
  $this->manager($r);try{$rows=$batch->rows;$source=$batch->source_reference;$note=$batch->review_note;$readable=\App\Support\ReviewedImports::hash($rows)===$batch->rows_hash;}catch(\Throwable){$readable=false;}abort_unless($readable,409,'This receipt preview cannot be verified. Ask GTA to check it.');return $this->privateView('batch',compact('batch','rows','source','note'));
 }
 public function review(Request $r,Batch $batch) {
  $this->manager($r);$d=$r->validate(['version'=>['required','integer','min:1'],'decision'=>['required','in:apply,reject'],'note'=>['required','string','max:1000'],'confirmed'=>['accepted']]);Ops::review($r->user(),(int)$r->session()->get('auth_version'),$batch,(int)$d['version'],$d['decision'],$d['note']);return back()->with('status','Receipt batch '.$d['decision'].' decision recorded. Transport attempts, no-retry reservations and alert preferences are unchanged.');
 }
 public function withdraw(Request $r,Batch $batch) {
  $this->manager($r);$d=$r->validate(['version'=>['required','integer','min:1'],'note'=>['required','string','max:1000'],'confirmed'=>['accepted']]);Ops::withdraw($r->user(),(int)$r->session()->get('auth_version'),$batch,(int)$d['version'],$d['note']);return back()->with('status','Receipt batch withdrawn with history retained. Sending reservations and preferences are unchanged.');
 }
 public function delivery(Request $r,Delivery $delivery) {
  $this->manager($r);$position=Ops::position($delivery);$case=Followup::where('delivery_id',$delivery->id)->first();$proposal=null;try{$proposal=$case?->proposal;}catch(\Throwable){$proposal=['unreadable'=>true];}
  $records=Receipt::where('delivery_id',$delivery->id)->orderByDesc('occurred_at')->orderBy('id')->get();$references=[];foreach($records as$record){try{$references[$record->id]=$record->source_reference;}catch(\Throwable){$references[$record->id]='Cannot verify private reference';}}
  return $this->privateView('delivery',['delivery'=>$delivery,'position'=>$position,'case'=>$case,'proposal'=>$proposal,'records'=>$records,'references'=>$references,'people'=>User::where('active',true)->whereIn('role',['admin','ceo'])->get(['id','name']),'events'=>DB::table('alert_receipt_events')->where('delivery_id',$delivery->id)->orderBy('id')->get(['event','actor_id','record_version','created_at','private_note'])->map(function($e){try{$e->note=$e->private_note!==null?Crypt::decryptString($e->private_note):null;}catch(\Throwable){$e->note='Private note cannot be verified';}return $e;})]);
 }
 public function saveCase(Request $r,Delivery $delivery) {
  $this->manager($r);$d=$r->validate(['version'=>['required','integer','min:0'],'owner_id'=>['nullable','integer','min:1'],'due_on'=>['nullable','date_format:Y-m-d','after_or_equal:today'],'note'=>['required','string','max:1000'],'confirmed'=>['accepted']]);Ops::saveCase($r->user(),(int)$r->session()->get('auth_version'),$delivery,(int)$d['version'],isset($d['owner_id'])?(int)$d['owner_id']:null,$d['due_on']??null,$d['note']);return back()->with('status','Follow-up saved. No contact was sent, no retry queued and no alert preference changed.');
 }
 public function proposeClose(Request $r,Followup $case) {
  $this->manager($r);$d=$r->validate(['version'=>['required','integer','min:1'],'record_id'=>['nullable','uuid'],'note'=>['required','string','max:1000'],'confirmed'=>['accepted']]);Ops::proposeClose($r->user(),(int)$r->session()->get('auth_version'),$case,(int)$d['version'],$d['record_id']??null,$d['note']);return back()->with('status','Closure/source-selection proposal saved. A different administrator/CEO must check it.');
 }
 public function checkClose(Request $r,Followup $case) {
  $this->manager($r);$d=$r->validate(['version'=>['required','integer','min:1'],'decision'=>['required','in:close,return'],'note'=>['required','string','max:1000'],'confirmed'=>['accepted']]);Ops::checkClose($r->user(),(int)$r->session()->get('auth_version'),$case,(int)$d['version'],$d['decision'],$d['note']);return back()->with('status','Independent follow-up decision recorded. It does not send or retry a reminder or change consent.');
 }
}
