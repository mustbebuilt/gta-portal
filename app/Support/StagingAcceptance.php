<?php
namespace App\Support;
use App\Models\{StagingAcceptanceRun as Run,StagingAcceptanceCheck as Check,StagingAcceptanceIssue as Issue,User};
use Illuminate\Support\Facades\{Crypt,DB};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
class StagingAcceptance {
 public const SCENARIOS=[
  'accounts'=>['Accounts and role scope','Create/link/invite correct apprentice and employer accounts; own-profile access, stale sessions and wrong-role/direct-URL refusal.'],
  'mfa'=>['Staff sign-in and recovery','Actual scoped MFA/recovery and privileged access; no bypass from enrolment being disabled.'],
  'navigation'=>['Browser, phone and accessibility','Actual narrow-screen/keyboard/focus/screen-reader/no-JS navigation and form error recovery.'],
  'tasks'=>['Tasks and marking','Declared programme/version tasks, submission/refer/approve and marking worklist; no missing source silently approved.'],
  'private-files'=>['Private evidence access','Real configured private storage/upload/download, wrong-user refusal and exact file-byte access.'],
  'attendance-otj'=>['Attendance and training hours','Actual registers, eligible hours, historical overlap/source checks and separate training evidence.'],
  'maths-english'=>['Maths and English','Assigned topics/planned hours, tutor topic marking and separate qualification result.'],
  'reviews'=>['Reviews and participant sign-off','Factual preparation, exact current version and three correct named participants; stale/other-user refusal.'],
  'support'=>['Private support and workplace sharing','Actual Inclusion scope/private plan, minimal summary allow/decline/withdraw and named employer access only.'],
  'questionnaires'=>['Questionnaire privacy and wording','Actual approved wording, own private draft/submission and checked scoped publication/follow-up.'],
  'employer-gateway'=>['Employer actions and Gateway','Named current workplace authority, actual approved programme/cohort criteria and unknown/missing items remain visible.'],
  'careers-destinations'=>['Careers and leaver follow-up','Private adviser scope, actual contact/dates, independent destination application/correction and reported/verified distinction.'],
  'staff-privacy'=>['Staff development privacy','Actual manager/self visibility for current supplied staff-development records; no unsupported HR policy/signature claim.'],
  'content'=>['Authoritative content in declared scope','GTA confirms actual task/cohort/KSB/Gateway/questionnaire sources in this run; unresolved content stays blocked or outside declared scope.'],
  'native-database'=>['Native migrations and concurrency','Actual managed engine/version, UUID/FKs/indexes/uniqueness, native races/locks/transactions and source/schema mapping.'],
  'backup-restore'=>['Compatible backup and restore','Actual isolated full DB/private storage/source/config/APP_KEY restore with later corrections/withdrawals reconciled.'],
  'csv'=>['Operational CSV readers','Actual chosen reader/Excel, scope/privacy/quoting/non-Latin/formula-like data and complete filtered exports.'],
  'receipt-operations'=>['Message evidence and follow-up','Declared normalised fixture imports, independent apply/conflict/correction/closure and no-retry/preferences preserved; actual provider mapping belongs to the optional live pilot.'],
  'live-messaging'=>['Live messaging service pilot','Only if included: actual authorised SMTP/SMS sender/verified recipient/consent, controlled provider/service evidence and resource/scheduler acceptance.'],
 ];
 public static function allowed(User $u):bool {return LearningAccess::manager($u);}
 private static function fail(string $m='The source or record changed. Reload; changed source requires a new run.'):never {throw ValidationException::withMessages(['acceptance'=>$m]);}
 private static function actor(User $u,int $auth):User {$u=User::whereKey($u->id)->lockForUpdate()->firstOrFail();abort_unless(self::allowed($u)&&$u->auth_version===$auth,403);return $u;}
 private static function event(Run $r,User $u,string $event,int $version,array $payload=[]):void {DB::table('staging_acceptance_events')->insert(['run_id'=>$r->id,'actor_id'=>$u->id,'event'=>$event,'record_version'=>$version,'payload'=>Crypt::encryptString(json_encode($payload,JSON_THROW_ON_ERROR)),'created_at'=>now()]);}
 public static function scenarios(Run $r):array {$all=self::SCENARIOS;if(!($r->details['live_messaging']??false)){unset($all['live-messaging']);}return $all;}
 public static function current(Run $r,?array $source=null):bool {
  try{$s=$source??app(AcceptanceSource::class)->capture();return AcceptanceSource::valid($s)&&AcceptanceSource::valid($r->source_snapshot)&&$r->source_hash===$s['fingerprint']&&$r->source_snapshot['fingerprint']===$r->source_hash&&ReviewedImports::hash($r->details)===$r->details_hash;}catch(\Throwable){return false;}
 }
 private static function open(Run $r,?array $s=null):void {if($r->state!=='Open'||!self::current($r,$s)){self::fail('Use an open run with the same captured source. Reopen an accepted run for new findings, or create a new-source run after code/config changes.');}}
 public static function create(User $actor,int $auth,array $details):Run {
  $source=app(AcceptanceSource::class)->capture();if(!AcceptanceSource::valid($source)){self::fail('Source capture is blocked. Check the read-only source diagnostic before creating a run.');}
  return DB::transaction(function()use($actor,$auth,$details,$source):Run {
   $u=self::actor($actor,$auth);$r=new Run(['created_by'=>$u->id,'state'=>'Open','version'=>1,'details'=>$details,'details_hash'=>ReviewedImports::hash($details),'source_snapshot'=>$source,'source_hash'=>$source['fingerprint']]);$r->id=(string)Str::uuid();$r->save();self::event($r,$u,'run_created',1);return $r;
  });
 }
 private static function checkHash(Check $c):string {return ReviewedImports::hash($c->only(['run_id','scenario_id','outcome','tested_at','recorded_by','details_hash']));}
 public static function verified(Check $c,Run $r):bool {
  try{$p=$c->review_proof;return $c->review_state==='Accepted'&&$c->reviewed_by&&$c->reviewed_by!==$c->recorded_by&&$c->reviewed_at&&ReviewedImports::hash($c->details)===$c->details_hash&&$p&&$p['source_hash']===$r->source_hash&&$p['check_hash']===self::checkHash($c)&&$p['version']===$c->version&&$p['reviewed_by']===$c->reviewed_by&&$p['reviewed_at']===$c->reviewed_at->toIso8601String();}catch(\Throwable){return false;}
 }
 public static function recordCheck(User $actor,int $auth,Run $record,string $scenario,int $version,array $data):void {
  $source=app(AcceptanceSource::class)->capture();DB::transaction(function()use($actor,$auth,$record,$scenario,$version,$data,$source):void {
   $u=self::actor($actor,$auth);$r=Run::whereKey($record->id)->lockForUpdate()->firstOrFail();self::open($r,$source);abort_unless(isset(self::scenarios($r)[$scenario]),404);
   $c=Check::where('run_id',$r->id)->where('scenario_id',$scenario)->lockForUpdate()->first();if(($c?->version??0)!==$version){self::fail('This scenario record changed. Reload before recording.');}
   $tested=\Carbon\Carbon::parse($data['tested_at'],'UTC')->setMicrosecond(0);if($tested->lt($r->created_at->copy()->setMicrosecond(0))||$tested->gt(now())||!in_array($data['outcome'],['Passed','Failed','Blocked'],true)){self::fail('Record an actual test time since this source run was created, not a future or pre-run result.');}
   $before=$c?['outcome'=>$c->outcome,'details'=>$c->details,'version'=>$c->version,'review_state'=>$c->review_state]:null;
   $details=['reference'=>$data['reference'],'note'=>$data['note']];$c??=new Check(['run_id'=>$r->id,'scenario_id'=>$scenario,'version'=>0]);$c->outcome=$data['outcome'];$c->tested_at=$tested;$c->recorded_by=$u->id;$c->details=$details;$c->details_hash=ReviewedImports::hash($details);$c->review_state='Awaiting check';$c->reviewed_by=null;$c->reviewed_at=null;$c->review_note=null;$c->review_proof=null;$c->version++;$c->save();$r->version++;$r->save();self::event($r,$u,'scenario_recorded',$c->version,['scenario'=>$scenario,'before'=>$before,'after'=>['outcome'=>$c->outcome,'details'=>$details]]);
  });
 }
 public static function reviewCheck(User $actor,int $auth,Check $record,int $version,string $decision,string $note):void {
  $source=app(AcceptanceSource::class)->capture();DB::transaction(function()use($actor,$auth,$record,$version,$decision,$note,$source):void {
   $u=self::actor($actor,$auth);$r=Run::whereKey($record->run_id)->lockForUpdate()->firstOrFail();self::open($r,$source);$c=Check::whereKey($record->id)->lockForUpdate()->firstOrFail();
   if($c->version!==$version||$c->review_state!=='Awaiting check'||$c->recorded_by===$u->id||!in_array($decision,['accept','return'],true)||ReviewedImports::hash($c->details)!==$c->details_hash){self::fail('Use the current result, checked by a different administrator/CEO.');}
   $c->review_state=$decision==='accept'?'Accepted':'Returned';$c->reviewed_by=$u->id;$c->reviewed_at=now()->setMicrosecond(0);$c->review_note=$note;$c->version++;$c->review_proof=$decision==='accept'?['source_hash'=>$r->source_hash,'check_hash'=>self::checkHash($c),'version'=>$c->version,'reviewed_by'=>$u->id,'reviewed_at'=>$c->reviewed_at->toIso8601String()]:null;$c->save();$r->version++;$r->save();self::event($r,$u,'scenario_'.$decision,$c->version,['scenario'=>$c->scenario_id,'note'=>$note]);
  });
 }
 private static function issueHash(Issue $i):string {return ReviewedImports::hash($i->only(['run_id','scenario_id','severity','owner_id','due_on','details_hash']));}
 public static function issueVerified(Issue $i,Run $r):bool {
  try{$p=$i->review_proof;return in_array($i->state,['Resolved','Deferred'],true)&&($i->state!=='Deferred'||$i->severity==='Minor')&&$p&&$p['state']===$i->state&&$p['version']===$i->version&&$p['issue_hash']===self::issueHash($i)&&$p['source_hash']===$r->source_hash&&$p['reviewed_by']===$i->reviewed_by&&$i->reviewed_at&&($p['reviewed_at']??null)===$i->reviewed_at->toIso8601String()&&$i->proposal&&$p['reviewed_by']!==$i->proposal['prepared_by']&&ReviewedImports::hash($i->details)===$i->details_hash;}catch(\Throwable){return false;}
 }
 public static function saveIssue(User $actor,int $auth,Run $record,?Issue $issue,int $version,array $data):Issue {
  $source=app(AcceptanceSource::class)->capture();return DB::transaction(function()use($actor,$auth,$record,$issue,$version,$data,$source):Issue {
   $u=self::actor($actor,$auth);$r=Run::whereKey($record->id)->lockForUpdate()->firstOrFail();self::open($r,$source);$i=$issue?Issue::whereKey($issue->id)->where('run_id',$r->id)->lockForUpdate()->firstOrFail():null;
   if(($i?->version??0)!==$version||!in_array($data['severity'],['Critical','Major','Minor'],true)){self::fail();}
   if(!empty($data['scenario_id'])&&!isset(self::scenarios($r)[$data['scenario_id']])){self::fail('Choose a scenario in this declared run.');}
   if(!empty($data['owner_id'])){$owner=User::whereKey($data['owner_id'])->first();if(!$owner||!self::allowed($owner)){self::fail('Choose a current active administrator/CEO owner.');}}
   $before=$i?['state'=>$i->state,'details'=>$i->details,'version'=>$i->version]:null;$details=['title'=>$data['title'],'detail'=>$data['detail'],'reference'=>$data['reference']];
   $i??=new Issue(['run_id'=>$r->id,'recorded_by'=>$u->id,'version'=>0]);if(!$i->id){$i->id=(string)Str::uuid();}$i->scenario_id=$data['scenario_id']??null;$i->severity=$data['severity'];$i->owner_id=$data['owner_id']??null;$i->due_on=$data['due_on']??null;$i->details=$details;$i->details_hash=ReviewedImports::hash($details);$i->state='Open';$i->proposal=null;$i->review_proof=null;$i->reviewed_by=null;$i->reviewed_at=null;$i->version++;$i->save();$r->version++;$r->save();self::event($r,$u,'issue_saved',$i->version,['issue'=>$i->id,'before'=>$before]);return $i;
  });
 }
 public static function proposeIssue(User $actor,int $auth,Issue $record,int $version,string $decision,string $reference,string $note):void {
  $source=app(AcceptanceSource::class)->capture();DB::transaction(function()use($actor,$auth,$record,$version,$decision,$reference,$note,$source):void {
   $u=self::actor($actor,$auth);$r=Run::whereKey($record->run_id)->lockForUpdate()->firstOrFail();self::open($r,$source);$i=Issue::whereKey($record->id)->lockForUpdate()->firstOrFail();
   if($i->version!==$version||$i->state!=='Open'||!in_array($decision,['resolve','defer'],true)||($decision==='defer'&&$i->severity!=='Minor')||ReviewedImports::hash($i->details)!==$i->details_hash){self::fail('Only a current open issue can be proposed; only Minor findings may be deferred.');}
   $i->proposal=['prepared_by'=>$u->id,'auth_version'=>$u->auth_version,'decision'=>$decision,'reference'=>$reference,'note'=>$note,'source_hash'=>$r->source_hash,'issue_hash'=>self::issueHash($i)];$i->state='Proposed';$i->version++;$i->save();$r->version++;$r->save();self::event($r,$u,'issue_resolution_proposed',$i->version,['issue'=>$i->id]);
  });
 }
 public static function reviewIssue(User $actor,int $auth,Issue $record,int $version,string $decision,string $note):void {
  $source=app(AcceptanceSource::class)->capture();DB::transaction(function()use($actor,$auth,$record,$version,$decision,$note,$source):void {
   $u=self::actor($actor,$auth);$r=Run::whereKey($record->run_id)->lockForUpdate()->firstOrFail();self::open($r,$source);$i=Issue::whereKey($record->id)->lockForUpdate()->firstOrFail();$p=$i->proposal;
   if($i->version!==$version||$i->state!=='Proposed'||!$p||$p['prepared_by']===$u->id||!in_array($decision,['accept','return'],true)){self::fail('Use a current proposal checked by a different administrator/CEO.');}
   if($decision==='accept'){$proposer=User::find($p['prepared_by']);if(!$proposer||!self::allowed($proposer)||$proposer->auth_version!==$p['auth_version']||$p['source_hash']!==$r->source_hash||$p['issue_hash']!==self::issueHash($i)||($p['decision']==='defer'&&$i->severity!=='Minor')){self::fail();}$i->state=$p['decision']==='resolve'?'Resolved':'Deferred';}else{$i->state='Open';$i->proposal=null;}
   $i->reviewed_by=$u->id;$i->reviewed_at=now()->setMicrosecond(0);$i->version++;$i->review_proof=$decision==='accept'?['state'=>$i->state,'issue_hash'=>self::issueHash($i),'source_hash'=>$r->source_hash,'version'=>$i->version,'reviewed_by'=>$u->id,'reviewed_at'=>$i->reviewed_at->toIso8601String()]:null;$i->save();$r->version++;$r->save();self::event($r,$u,'issue_'.$decision,$i->version,['issue'=>$i->id,'note'=>$note]);
  });
 }
 private static function coverage(Run $r):array {
  $checks=Check::where('run_id',$r->id)->orderBy('scenario_id')->get()->keyBy('scenario_id');$issues=Issue::where('run_id',$r->id)->orderBy('id')->get();$missing=[];$passed=0;$deferred=0;
  foreach(self::scenarios($r)as$id=>$scenario){$c=$checks->get($id);if(!$c||$c->outcome!=='Passed'||!self::verified($c,$r)){$missing[]=$id;}else{$passed++;}}
  $blocking=[];foreach($issues as$i){if(!self::issueVerified($i,$r)){$blocking[]=$i->id;}elseif($i->state==='Deferred'){$deferred++;}}
  $evidence=['checks'=>$checks->map(fn($c)=>['id'=>$c->id,'scenario'=>$c->scenario_id,'version'=>$c->version,'outcome'=>$c->outcome,'review'=>$c->review_state,'details_hash'=>$c->details_hash,'proof_hash'=>$c->review_proof?ReviewedImports::hash($c->review_proof):null])->values()->all(),'issues'=>$issues->map(fn($i)=>['id'=>$i->id,'version'=>$i->version,'state'=>$i->state,'severity'=>$i->severity,'details_hash'=>$i->details_hash,'proof_hash'=>$i->review_proof?ReviewedImports::hash($i->review_proof):null])->values()->all()];
  return ['required'=>count(self::scenarios($r)),'passed'=>$passed,'missing'=>$missing,'blocking_issues'=>$blocking,'deferred_minor'=>$deferred,'evidence_hash'=>ReviewedImports::hash($evidence),'evidence'=>$evidence];
 }
 public static function position(Run $r,?array $source=null):array {
  try{$current=self::current($r,$source);$c=self::coverage($r);$ready=$current&&!$c['missing']&&!$c['blocking_issues'];$accepted=false;
   if($r->state==='Accepted'){$p=$r->receipt;$accepted=$ready&&$r->accepted_by&&$r->accepted_by!==$r->created_by&&$r->accepted_at&&$p&&$p['source_hash']===$r->source_hash&&$p['details_hash']===$r->details_hash&&$p['evidence_hash']===$c['evidence_hash']&&$p['accepted_by']===$r->accepted_by&&$p['accepted_at']===$r->accepted_at->toIso8601String()&&$p['version']===$r->version;}
   return [...$c,'source_current'=>$current,'ready'=>$ready&&$r->state==='Open','accepted'=>$accepted,'label'=>!$current?'Source changed / needs new run':($accepted?'Accepted for declared staging scope':($r->state==='Accepted'?'Acceptance evidence needs checking':($ready?'Ready for independent staging acceptance':'Work outstanding'))),'production_approved'=>false];
  }catch(\Throwable){return ['required'=>0,'passed'=>0,'missing'=>[],'blocking_issues'=>[],'deferred_minor'=>0,'evidence_hash'=>null,'source_current'=>false,'ready'=>false,'accepted'=>false,'label'=>'Evidence cannot be verified','production_approved'=>false];}
 }
 public static function accept(User $actor,int $auth,Run $record,int $version,string $note):void {
  $source=app(AcceptanceSource::class)->capture();DB::transaction(function()use($actor,$auth,$record,$version,$note,$source):void {
   $u=self::actor($actor,$auth);$r=Run::whereKey($record->id)->lockForUpdate()->firstOrFail();self::open($r,$source);if($r->version!==$version||$r->created_by===$u->id){self::fail('Use a current run accepted by a different administrator/CEO from its creator.');}$p=self::position($r,$source);if(!$p['ready']){self::fail('Every required scenario needs a different-person checked Passed result and every finding needs accepted resolution or a checked Minor deferral.');}
   $r->state='Accepted';$r->accepted_by=$u->id;$r->accepted_at=now()->setMicrosecond(0);$r->version++;$r->receipt=['source_hash'=>$r->source_hash,'details_hash'=>$r->details_hash,'evidence_hash'=>$p['evidence_hash'],'accepted_by'=>$u->id,'accepted_at'=>$r->accepted_at->toIso8601String(),'version'=>$r->version,'note'=>$note,'deferred_minor'=>$p['deferred_minor']];$r->save();self::event($r,$u,'scoped_staging_accepted',$r->version,['receipt'=>$r->receipt]);
  });
 }
 public static function reopen(User $actor,int $auth,Run $record,int $version,string $note):void {
  $source=app(AcceptanceSource::class)->capture();DB::transaction(function()use($actor,$auth,$record,$version,$note,$source):void {
   $u=self::actor($actor,$auth);$r=Run::whereKey($record->id)->lockForUpdate()->firstOrFail();if($r->version!==$version||$r->state!=='Accepted'||!self::current($r,$source)){self::fail('Reopen only a same-source accepted run. Changed source needs a new run.');}$before=$r->receipt;$previousChecks=[];foreach(Check::where('run_id',$r->id)->lockForUpdate()->get()as$c){$previousChecks[]=['id'=>$c->id,'scenario'=>$c->scenario_id,'version'=>$c->version,'details'=>$c->details,'proof'=>$c->review_proof];$c->review_state='Returned';$c->review_proof=null;$c->reviewed_by=null;$c->reviewed_at=null;$c->review_note='Run reopened: record a fresh observation before independent review.';$c->version++;$c->save();}$r->state='Open';$r->receipt=null;$r->accepted_by=null;$r->accepted_at=null;$r->version++;$r->save();self::event($r,$u,'acceptance_reopened',$r->version,['previous_receipt'=>$before,'previous_checks'=>$previousChecks,'reason'=>$note]);
  });
 }
 public static function fork(User $actor,int $auth,Run $record,array $details):Run {
  $source=app(AcceptanceSource::class)->capture();if(!AcceptanceSource::valid($source)){self::fail('Current source capture is blocked.');}
  return DB::transaction(function()use($actor,$auth,$record,$details,$source):Run {
   $u=self::actor($actor,$auth);$old=Run::whereKey($record->id)->lockForUpdate()->firstOrFail();$r=new Run(['parent_id'=>$old->id,'created_by'=>$u->id,'state'=>'Open','version'=>1,'details'=>$details,'details_hash'=>ReviewedImports::hash($details),'source_snapshot'=>$source,'source_hash'=>$source['fingerprint']]);$r->id=(string)Str::uuid();$r->save();
   foreach(Issue::where('run_id',$old->id)->get()as$i){if($i->state==='Resolved'&&self::issueVerified($i,$old)){continue;}if(ReviewedImports::hash($i->details)!==$i->details_hash){self::fail('Parent finding cannot be verified. Reconcile the source evidence first.');}$copy=new Issue(['run_id'=>$r->id,'parent_id'=>$i->id,'scenario_id'=>isset(self::scenarios($r)[$i->scenario_id??''])?$i->scenario_id:null,'recorded_by'=>$u->id,'owner_id'=>$i->owner_id&&($owner=User::find($i->owner_id))&&self::allowed($owner)?$owner->id:null,'due_on'=>$i->due_on,'severity'=>$i->severity,'state'=>'Open','details'=>$i->details,'details_hash'=>$i->details_hash,'version'=>1]);$copy->id=(string)Str::uuid();$copy->save();}self::event($r,$u,'new_source_run_created',1,['parent'=>$old->id,'checks_carried_forward'=>false]);return $r;
  });
 }
}
