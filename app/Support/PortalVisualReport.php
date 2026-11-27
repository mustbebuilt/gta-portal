<?php
namespace App\Support;
use App\Models\{Apprentice,LearningTask,Qualification,SmartAction,TrainingTopic};
use Illuminate\Http\Request;
use Carbon\Carbon;
class PortalVisualReport
{
 public static function capture(Request $r):array
 {
  $u=PortalActions::actor($r->user());abort_unless(in_array($u->role,['admin','ceo','tutor','apprentice'],true),403);
  if($u->role==='apprentice'){abort_unless(ApprenticeExperience::profile($u),409,'Ask GTA to check your single linked apprentice profile.');}
  $data=$r->validate(['group'=>['nullable','integer','min:1'],'apprentice'=>['nullable','integer','min:1'],'reference'=>['nullable','string','max:40'],'from'=>['nullable','date_format:Y-m-d','before_or_equal:today'],'to'=>['nullable','date_format:Y-m-d','before_or_equal:today']]);
  $from=$data['from']??today()->subWeeks(11)->startOfWeek()->toDateString();$to=$data['to']??today()->toDateString();
  abort_unless($from<=$to&&Carbon::parse($from)->diffInDays(Carbon::parse($to))<=366,422,'Choose an attendance interval of up to one year.');
  $scope=Apprentice::visibleTo($u)->when($data['group']??null,fn($q,$id)=>$q->where('tutor_group_id',$id))->when($data['apprentice']??null,fn($q,$id)=>$q->whereKey($id));
  if (!empty($data['reference'])) {
   $reference = strtoupper(trim($data['reference']));
   $person = (clone $scope)->where('reference', $reference)->first(['id']);
   abort_unless($person, 422, 'No matching apprentice was found in this selection. Check the reference and tutor group.');
   $scope->whereKey($person->id);
   $data['apprentice'] = $person->id;
  }
  if($data['apprentice']??null){abort_unless((clone$scope)->exists(),404);}
  abort_if((clone $scope)->count() > 200, 422, 'This report covers more than 200 records, including any historic records in the selection. Choose a tutor group or an apprentice.');
  $people = $scope->with('programmeRecord')->orderBy('last_name')->get();
  $ids = $people->pluck('id')->all();
  $tasks = LearningTask::whereIn('apprentice_id', $ids)
   ->get(['id', 'apprentice_id', 'status', 'template->kind as summary_kind'])
   ->each(function (LearningTask $task): void {
    $task->setAttribute('template', $task->summary_kind !== null ? ['kind' => $task->summary_kind] : []);
   });
  $topics = TrainingTopic::whereIn('apprentice_id', $ids)->get(['id', 'subject', 'status', 'planned_hours']);
  $ledger = AttendanceLedger::reportPeriod($ids, $from, $to);
  $charts = [PortalVisuals::tasks($tasks), PortalVisuals::topics($topics)];
  $programmes=[];$attendanceRows=[];$details=[];
  foreach($people as $a){
   $p=$a->programmeLabel()??'Programme not recorded';$programmes[$p]=($programmes[$p]??0)+1;
   $rows = $ledger[$a->id]['rows'] ?? [];
   $attendanceRows=[...$attendanceRows,...$rows];$attendance = $ledger === null ? null : AttendanceMath::report($rows);$position=OtjPosition::forApprentice($a);$otj=$position['summary'];$headline=OtjPosition::headline($position);$cycle=SignedReviews::cycle($a);
   $allocated = $tasks->where('apprentice_id', $a->id);
   $decisions = PortalVisuals::tasks($allocated)['rows'];
   $details[]=['id'=>$a->id,'reference'=>$a->reference,'name'=>$a->first_name.' '.$a->last_name,'programme'=>$p,'tasks'=>$allocated->count(),'approved'=>collect($decisions)->firstWhere('label','Approved')['value'],'attendance'=>$attendance,'recorded_hours'=>$headline['recorded_hours'],'target_hours'=>$headline['target_hours'],'planned_due_hours'=>$otj['planned_due_hours'],'progressive_expected_hours'=>$headline['expected_hours'],'progressive_gap_hours'=>$headline['gap_hours'],'otj_pending'=>$otj['pending_sources'],'otj_checks'=>$otj['invalid_sources'],'review_due'=>$cycle['next_due']];
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
   $start=max($from,$week->toDateString());$finish=min($to,$week->copy()->endOfWeek()->toDateString());$rows=array_values(array_filter($attendanceRows,fn($row)=>$row['date']>=$start&&$row['date']<=$finish));$a = $ledger === null ? null : AttendanceMath::report($rows);$weekly[]=['label'=>$week->format('d M'),'value'=>isset($a['percent'])?round($a['percent'],1):null,'from'=>$start,'to'=>$finish,'attended'=>$a['attended']??null,'expected'=>$a['expected']??null,'unmarked'=>$a['unmarked']??null];$week->addWeek();
  }
  $charts[]=['key'=>'attendance-trend','title'=>'Weekly apprentice-session attendance','type'=>'line','max'=>100,'unit'=>'% of expected apprentice-session records','note'=>'Ended saved-roster sessions in the selected attendance period. Aggregate attended / expected, not an average of individual percentages. Missing marks or no expected records give Unknown and a break in the line. The available weeks use the same register basis as the period total.','rows'=>$weekly];
  if(count($details)===1){$a=$details[0];$charts[]=['key'=>'otj','title'=>'Recorded main-aim training and approved plan','type'=>'bars','unit'=>'hours','note'=>'Current loaded source records, separate from the attendance date filter. Recorded hours, approved target and planned hours in blocks already ended are different measures. No daily interpolation or historical completeness is inferred. Maths/English topic hours are not added.','rows'=>[['label'=>'Recorded loaded-source hours','value'=>$a['recorded_hours']],['label'=>'Current approved total target','value'=>$a['target_hours']],['label'=>'Approved blocks ended to date','value'=>$a['planned_due_hours']]]];}
  $peopleById = $people->keyBy('id');
  $quals=Qualification::whereIn('apprentice_id',$ids)->get()->map(fn($q)=>['reference'=>$peopleById->get($q->apprentice_id)?->reference,'subject'=>$q->subject,'title'=>$q->title,'status'=>$q->status,'result'=>$q->result??'Not recorded'])->values()->all();
  $attendanceTotal = $ledger === null ? null : AttendanceMath::report($attendanceRows);
  return ['attendance_total' => $attendanceTotal,'attendance_blocked'=>$ledger===null,'title'=>$u->role==='apprentice'?'My report':(LearningAccess::manager($u)?'Full portal report':'My caseload report'),'captured_at'=>now()->toIso8601String(),'scope_label'=>$u->role==='apprentice'?'Your linked apprentice record':(LearningAccess::manager($u)?'Selected authorised management scope':'Current assigned main-tutor scope'),'filters'=>['group'=>$data['group']??null,'apprentice'=>$data['apprentice']??null,'from'=>$from,'to'=>$to],'attendance_from'=>$from,'attendance_to'=>$to,'record_count'=>count($details),'charts'=>$charts,'details'=>$details,'otj_followup'=>$otjFollowup,'qualifications'=>$quals,'attendance_weekly'=>$weekly,'definitions'=>[
   'Task decisions are current allocated records. Source-pending content is separate; task approval is not full apprenticeship or KSB achievement.',
   'Attendance uses the existing portal rules: O/T attended; A/H/S/W missed; C/B/E excluded; missing/unknown marks prevent a final percentage. Cancelled sessions are excluded. The unit is an apprentice-session record, not a unique apprentice.',
   'Maths/English positive-hour topic completion and qualification results are separate; topic hours are not added to main-aim OTJ.',
   'OTJ uses the existing confirmed session, approved extra-training and checked historical ledger. Missing sources and approved-plan gaps remain visible. The attendance date filter does not reconstruct past OTJ plans.',
   'Review dates are checked-cycle due references, not booked appointments. No official QAR, compliance score, staff efficiency or sample threshold is inferred.',
   'Current task/topic/plan/outcome context is captured now. Only the attendance trend uses the stated period. Tables provide exact values; unknown is not zero.'
  ]];
 }
}
