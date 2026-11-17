<?php
namespace App\Http\Controllers;
use App\Models\{Apprentice,ApprenticeEntryContext as Entry,EntryContextEvent as Event,Employer,ProgrammeOutcome,DestinationMilestone as Milestone,DestinationMilestoneCheck as Check,RetentionAdminAssignment as Assignment,User,SupportConcern,SupportPlan};
use App\Support\{EntryContext,DestinationMilestones as Followup,LearningAccess};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
class RecruitmentRetentionController extends Controller {
 private function manager(Request $r):void {abort_unless(LearningAccess::manager($r->user()),403);}
 private function view(string $name,array $data=[]){return response()->view('retention.'.$name,$data)->header('Cache-Control','no-store, private')->header('Referrer-Policy','no-referrer');}
 public function entry(Request $r,Apprentice $apprentice){
  $this->manager($r);$r->validate(['page'=>['nullable','integer','min:1']]);
  return $this->view('entry',['apprentice'=>$apprentice,'entry'=>Entry::where('apprentice_id',$apprentice->id)->first(),'choices'=>EntryContext::choices(),'employers'=>Employer::orderBy('name')->get(['id','name']),'events'=>Event::where('apprentice_id',$apprentice->id)->orderByDesc('id')->paginate(15)]);
 }
 public function save(Request $r,Apprentice $apprentice){$this->manager($r);$d=$r->validate(['version'=>['required','integer','min:0'],'reason'=>['nullable','string','max:500']]);EntryContext::save($r->user(),$apprentice,$r->all(),(int)$d['version'],$d['reason']??'');return back()->with('status','Entry information saved. Original values remain in correction history.');}
 public function employment(Request $r,Apprentice $apprentice){$this->manager($r);EntryContext::employment($r->user(),$apprentice,$r->all());return back()->with('status','Dated employment circumstances recorded. Recruitment and entry information retained.');}
 public function queue(Request $r){
  $this->manager($r);$d=$r->validate(['page'=>['nullable','integer','min:1'],'show'=>['nullable','in:quality,followup'],'mine'=>['nullable','in:1']]);$show=$d['show']??'quality';
  $owners=User::where('active',true)->whereIn('role',['admin','ceo'])->orderBy('name')->get(['id','name']);
  if($show==='followup'){
   $q=Milestone::where('state','!=','Superseded')->where(function($q){$q->where('state','Open')->orWhereNotNull('retry_on');})->when(isset($d['mine']),fn($q)=>$q->where('owner_id',$r->user()->id))->orderByRaw('COALESCE(retry_on,due_on)')->orderBy('id');$items=$q->paginate(25)->withQueryString();
   $people=Apprentice::whereIn('id',$items->pluck('apprentice_id'))->get()->keyBy('id');$gaps=[];$assignments=collect();
  }else{
   $q=Apprentice::where(function($q){$q->whereNotExists(function($s){$s->selectRaw('1')->from('apprentice_entry_contexts')->whereColumn('apprentice_entry_contexts.apprentice_id','apprentices.id');})->orWhereExists(function($s){$s->selectRaw('1')->from('apprentice_entry_contexts as e')->whereColumn('e.apprentice_id','apprentices.id')->where(function($s){$s->where('e.stage','!=','Enrolment')->orWhereNull('e.confirmed_on')->orWhereNull('e.entry_employer_id')->orWhere(function($s){$s->where('e.already_employed','Yes')->whereNull('e.employment_start_date');});foreach(array_keys(EntryContext::choices()) as $k){$s->orWhereNull('e.'.$k)->orWhere('e.'.$k,'Unknown');}$s->orWhere(function($q){$q->where('e.already_employed','Yes')->whereColumn('e.employment_start_date','>','apprentices.start_date');})->orWhere(function($q){$q->where('e.already_employed','No')->whereColumn('e.employment_start_date','<','apprentices.start_date');});});});});
   if(isset($d['mine'])){$q->whereIn('id',Assignment::where('owner_id',$r->user()->id)->select('apprentice_id'));}
   $items=$q->orderBy('id')->paginate(25)->withQueryString();$people=$items->getCollection()->keyBy('id');$entries=Entry::whereIn('apprentice_id',$people->keys())->get()->keyBy('apprentice_id');$assignments=Assignment::whereIn('apprentice_id',$people->keys())->get()->keyBy('apprentice_id');$gaps=[];foreach($people as $a){$gaps[$a->id]=EntryContext::gaps($entries->get($a->id),$a);}
  }
  return $this->view('admin-queue',compact('show','items','people','gaps','assignments','owners'));
 }
 public function qualityAssign(Request $r,Apprentice $apprentice){$this->manager($r);$d=$r->validate(['version'=>['required','integer','min:0'],'owner_id'=>['required','integer',Rule::exists('users','id')->where('active',true)->whereIn('role',['admin','ceo'])],'due_on'=>['required','date_format:Y-m-d']]);DB::transaction(function()use($r,$apprentice,$d){Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();$x=Assignment::where('apprentice_id',$apprentice->id)->lockForUpdate()->first();if(($x?->version??0)!==(int)$d['version']){throw ValidationException::withMessages(['version'=>'Assignment changed. Reload.']);}$before=$x?->only(['owner_id','due_on']);$x??=new Assignment(['apprentice_id'=>$apprentice->id]);$x->owner_id=$d['owner_id'];$x->due_on=$d['due_on'];$x->version=(int)$d['version']+1;$x->save();Event::create(['apprentice_id'=>$apprentice->id,'actor_id'=>$r->user()->id,'kind'=>'Data quality owner','revision'=>$x->version,'payload'=>['before'=>$before,'after'=>$x->only(['owner_id','due_on'])]]);});return back()->with('status','Data-quality action assigned separately from support.');}
 public function assign(Request $r,Milestone $milestone){$this->manager($r);$d=$r->validate(['version'=>['required','integer','min:1'],'owner_id'=>['required','integer']]);Followup::assign($r->user(),$milestone,(int)$d['version'],(int)$d['owner_id']);return back()->with('status','Destination check assigned.');}
 public function check(Request $r,Milestone $milestone){$this->manager($r);Followup::record($r->user(),$milestone,array_merge(['destination'=>'Unknown','basis'=>'Unknown','continuity'=>'Unknown'],$r->all()));return back()->with('status','Milestone check saved with dated history. Exit destination retained.');}
 public function timeline(Request $r,Apprentice $apprentice){
  $this->manager($r);$r->validate(['page'=>['nullable','integer','min:1']]);
  // Metadata only: do not duplicate private support notes into the retention timeline.
  $queries=[];foreach([
   ['entry_context_events','created_at','kind','Recruitment / employment'],['assignment_change_events','created_at',null,'Linked employer / staff change'],['support_concerns','created_at',null,'Concern raised'],['support_concerns','acknowledged_at',null,'Concern acknowledged'],['support_concerns','closed_at',null,'Concern closed'],['support_plans','created_at',null,'Support plan created'],['progress_review_drafts','updated_at',null,'Review last saved'],['destination_contacts','contacted_on',null,'Destination contact'],['destination_milestones','created_at',null,'Destination follow-up scheduled']
  ] as [$table,$date,$kind,$label]){$dateExpression=$table==='entry_context_events'?'COALESCE(effective_on,created_at)':$date;$q=DB::table($table)->selectRaw("id, $dateExpression as event_at, ".($kind?$kind:"?")." as label",$kind?[]:[$label])->where('apprentice_id',$apprentice->id)->whereNotNull($date);$queries[]=$q;}
  $union=array_shift($queries);foreach($queries as $q){$union->unionAll($q);}$items=DB::query()->fromSub($union,'timeline')->get();
  foreach(SupportPlan::where('apprentice_id',$apprentice->id)->get(['id','history','activities','reviews','closed_at']) as $plan){
   foreach($plan->history??[] as $h){if(($h['event']??null)==='approved'&&filled($h['at']??null)){$items->push((object)['id'=>$plan->id,'event_at'=>$h['at'],'label'=>'Support plan approved']);}}
   foreach($plan->activities??[] as $activity){if(filled($activity['date']??null)){$items->push((object)['id'=>$plan->id,'event_at'=>$activity['date'],'label'=>'Actual support delivered']);}}
   foreach($plan->reviews??[] as $review){if(filled($review['date']??null)){$items->push((object)['id'=>$plan->id,'event_at'=>$review['date'],'label'=>'Support plan reviewed']);}}
   if($plan->closed_at){$items->push((object)['id'=>$plan->id,'event_at'=>$plan->closed_at->toDateTimeString(),'label'=>'Support plan closed']);}
  }
  foreach(Check::whereIn('milestone_id',Milestone::where('apprentice_id',$apprentice->id)->select('id'))->get(['id','observed_on','result']) as $check){$items->push((object)['id'=>$check->id,'event_at'=>$check->observed_on->toDateString(),'label'=>'Milestone check: '.$check->result]);}
  $context=ProgrammeOutcome::where('apprentice_id',$apprentice->id)->first()?->context??[];
  if(\App\Support\CareersFollowup::date($context['endDate']??null)){$items->push((object)['id'=>0,'event_at'=>$context['endDate'],'label'=>'Recorded programme exit: '.($context['status']??'Unknown')]);}
  $items=$items->sortByDesc('event_at')->values();$page=(int)$r->input('page',1);$events=new \Illuminate\Pagination\LengthAwarePaginator($items->forPage($page,30)->values(),$items->count(),30,$page,['path'=>$r->url()]);
  return $this->view('timeline',compact('apprentice','events'));
 }
}
