<?php
namespace App\Support;
use App\Models\{Apprentice,SmartAction,User};
use Illuminate\Http\Request;
use Carbon\Carbon;
class PortalVisualReport
{
 public static function capture(Request $r):array
 {
  $u=PortalActions::actor($r->user());abort_unless(in_array($u->role,['admin','ceo','tutor','apprentice'],true),403);
  if($u->role==='apprentice'){abort_unless(ApprenticeExperience::profile($u),409,'Ask GTA to check your single linked apprentice profile.');}
  $data=$r->validate(['group'=>['nullable','integer','min:1'],'apprentice'=>['nullable','integer','min:1'],'from'=>['nullable','date_format:Y-m-d','before_or_equal:today'],'to'=>['nullable','date_format:Y-m-d','before_or_equal:today']]);
  $from=$data['from']??today()->subWeeks(11)->startOfWeek()->toDateString();$to=$data['to']??today()->toDateString();
  abort_unless($from<=$to&&Carbon::parse($from)->diffInDays(Carbon::parse($to))<=366,422,'Choose an attendance interval of up to one year.');
  $scope=Apprentice::visibleTo($u)->when($data['group']??null,fn($q,$id)=>$q->where('tutor_group_id',$id))->when($data['apprentice']??null,fn($q,$id)=>$q->whereKey($id));
  if($data['apprentice']??null){abort_unless((clone$scope)->exists(),404);}
  abort_if((clone$scope)->count()>200,422,'Narrow the report to at most200 apprentices.');
  $source=app(\App\Http\Controllers\DashboardController::class)->report($r)->getData();
  $charts=[PortalVisuals::tasks($source['tasks']),PortalVisuals::topics($source['topics'])];
  $programmes=[];$attendanceRows=[];$details=[];
  foreach($source['apprentices']as$a){
   $p=$a->programmeLabel()??'Programme not recorded';$programmes[$p]=($programmes[$p]??0)+1;
   $rows=array_values(array_filter($source['attendanceLedger'][$a->id]['rows'],fn($row)=>$row['date']>=$from&&$row['date']<=$to));
   $attendanceRows=[...$attendanceRows,...$rows];$attendance=AttendanceMath::report($rows);$otj=OtjPosition::forApprentice($a)['summary'];$cycle=SignedReviews::cycle($a);
   $tasks=$source['tasks']->where('apprentice_id',$a->id);$decisions=PortalVisuals::tasks($tasks)['rows'];
   $details[]=['id'=>$a->id,'reference'=>$a->reference,'name'=>$a->first_name.' '.$a->last_name,'programme'=>$p,'tasks'=>$tasks->count(),'approved'=>collect($decisions)->firstWhere('label','Approved')['value'],'attendance'=>$attendance,'recorded_hours'=>round($otj['recorded_minutes']/60,2),'target_hours'=>$otj['target_hours'],'planned_due_hours'=>$otj['planned_due_hours'],'progressive_expected_hours'=>$otj['progressive_expected_hours'],'progressive_gap_hours'=>$otj['progressive_gap_hours'],'otj_pending'=>$otj['pending_sources'],'otj_checks'=>$otj['invalid_sources'],'review_due'=>$cycle['next_due']];
  }
  $otjFollowup = [];
  if ($u->role !== 'apprentice') {
   $open = SmartAction::whereIn('apprentice_id', array_column($details, 'id'))
    ->where('relevance', SmartAction::OTJ_CATCHUP)->where('status', '!=', 'Completed')
    ->orderBy('due_date')->orderBy('id')->get(['id', 'apprentice_id', 'owner', 'due_date'])->groupBy('apprentice_id');
   foreach ($details as $row) {
    $action = $open->get($row['id'])?->first();
    if (($row['progressive_gap_hours'] ?? 0) > 0 || $action) {
     $row['catchup'] = $action ? ['id' => $action->id, 'owner' => $action->owner, 'due' => $action->due_date?->toDateString()] : null;
     $otjFollowup[] = $row;
    }
   }
   usort($otjFollowup, fn ($a, $b) => ($b['progressive_gap_hours'] ?? 0) <=> ($a['progressive_gap_hours'] ?? 0));
  }
  ksort($programmes);$charts[]=['key'=>'programmes','title'=>'Apprentices by programme','type'=>'bars','unit'=>'apprentices','note'=>'Current selected scope. Programme labels reflect source records; non-automotive programmes are retained. Charts group entries after the first13; the exact table lists every programme.','rows'=>PortalVisuals::rows($programmes)];
  $weekly=[];$week=Carbon::parse($from)->startOfWeek();$end=Carbon::parse($to);
  while($week->lte($end)){
   $start=max($from,$week->toDateString());$finish=min($to,$week->copy()->endOfWeek()->toDateString());$rows=array_values(array_filter($attendanceRows,fn($row)=>$row['date']>=$start&&$row['date']<=$finish));$a=AttendanceMath::report($rows);$weekly[]=['label'=>$week->format('d M'),'value'=>$a['percent']===null?null:round($a['percent'],1),'from'=>$start,'to'=>$finish,'attended'=>$a['attended'],'expected'=>$a['expected'],'unmarked'=>$a['unmarked']];$week->addWeek();
  }
  $charts[]=['key'=>'attendance-trend','title'=>'Weekly apprentice-session attendance','type'=>'line','max'=>100,'unit'=>'% of expected apprentice-session records','note'=>'Ended saved-roster sessions in the selected attendance period. Aggregate attended / expected, not an average of individual percentages. Missing marks or no expected records give Unknown and a break in the line. No illustrative90% threshold is adopted.','rows'=>$weekly];
  if(count($details)===1){$a=$details[0];$charts[]=['key'=>'otj','title'=>'Recorded main-aim training and approved plan','type'=>'bars','unit'=>'hours','note'=>'Current loaded source records, separate from the attendance date filter. Recorded hours, approved target and planned hours in blocks already ended are different measures. No daily interpolation or historical completeness is inferred. Maths/English topic hours are not added.','rows'=>[['label'=>'Recorded loaded-source hours','value'=>$a['recorded_hours']],['label'=>'Current approved total target','value'=>$a['target_hours']],['label'=>'Approved blocks ended to date','value'=>$a['planned_due_hours']]]];}
  $quals=$source['qualifications']->map(fn($q)=>['reference'=>$source['apprentices']->firstWhere('id',$q->apprentice_id)?->reference,'subject'=>$q->subject,'title'=>$q->title,'status'=>$q->status,'result'=>$q->result??'Not recorded'])->values()->all();
  return ['title'=>$u->role==='apprentice'?'My report':(LearningAccess::manager($u)?'Full portal report':'My caseload report'),'captured_at'=>now()->toIso8601String(),'scope_label'=>$u->role==='apprentice'?'Your linked apprentice record':(LearningAccess::manager($u)?'Selected authorised management scope':'Current assigned main-tutor scope'),'filters'=>['group'=>$data['group']??null,'apprentice'=>$data['apprentice']??null,'from'=>$from,'to'=>$to],'attendance_from'=>$from,'attendance_to'=>$to,'record_count'=>count($details),'charts'=>$charts,'details'=>$details,'otj_followup'=>$otjFollowup,'qualifications'=>$quals,'attendance_weekly'=>$weekly,'definitions'=>[
   'Task decisions are current allocated records. Source-pending content is separate; task approval is not full apprenticeship or KSB achievement.',
   'Attendance uses the existing portal rules: O/T attended; A/H/S/W missed; C/B/E excluded; missing/unknown marks prevent a final percentage. Cancelled sessions are excluded. The unit is an apprentice-session record, not a unique apprentice.',
   'Maths/English positive-hour topic completion and qualification results are separate; topic hours are not added to main-aim OTJ.',
   'OTJ uses the existing confirmed session, approved extra-training and checked historical ledger. Missing sources and approved-plan gaps remain visible. The attendance date filter does not reconstruct past OTJ plans.',
   'Review dates are checked-cycle due references, not booked appointments. No official QAR, compliance score, staff efficiency or sample threshold is inferred.',
   'Current task/topic/plan/outcome context is captured now. Only the attendance trend uses the stated period. Tables provide exact values; unknown is not zero.'
  ]];
 }
}
