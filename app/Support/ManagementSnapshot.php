<?php
namespace App\Support;

use App\Models\Apprentice;
use App\Models\LearningTask;
use App\Models\ProgressReviewDraft;
use App\Models\ProgrammeOutcome;
use App\Models\Qualification;
use App\Models\SmartAction;
use App\Models\TrainingTopic;

class ManagementSnapshot
{
    public static function capture($people): array
    {
        $ids=$people->pluck('id')->all();$attendance=AttendanceLedger::forIds($ids);$rows=[];$checks=[];
        foreach($people as $a){
            $tasks=LearningTask::where('apprentice_id',$a->id)->get();$qualifications=Qualification::where('apprentice_id',$a->id)->get();
            $topics=TrainingTopic::where('apprentice_id',$a->id)->get();$actions=SmartAction::with('task')->where('apprentice_id',$a->id)->get();
            $reviews=ProgressReviewDraft::where('apprentice_id',$a->id)->get();$reviewCycle=SignedReviews::cycle($a);$signedRecords=\App\Models\SignedReviewRecord::where('apprentice_id',$a->id)->get();$confirmedReviewIds=$signedRecords->filter(fn($r)=>$r->checked_at && !$r->withdrawn_at && SignedReviews::intact($r))->pluck('progress_review_draft_id')->all();$otjPosition=OtjPosition::forApprentice($a);$otj=$otjPosition['summary'];$plan=DeliveryPlan::approved($a);
            $issues=[];
            if(!$a->start_date){$issues[]='First learning date missing';}
            if(!$a->programme_id || !TaskCatalogue::keyFor($a)){$issues[]='Explicit loaded programme/version needs checking';}
            if(!$a->tutor || !$a->tutor->active || $a->tutor->role!=='tutor'){$issues[]='Active main tutor needs assigning';}
            if(!$plan){$issues[]='No applicable provider-approved delivery plan';}
            if($tasks->contains(fn($t)=>$t->status!=='Approved' && !$t->due_date)){$issues[]='Unfinished tasks have unset dates';}
            if(!$qualifications->where('subject','maths')->count()){$issues[]='Maths qualification requirements need recording/checking';}
            if(!$qualifications->where('subject','english')->count()){$issues[]='English qualification requirements need recording/checking';}
            if($qualifications->contains(fn($q)=>$q->position()==='Date check')){$issues[]='Qualification start/target dates need checking';}
            if($otj['pending_sources'] || $otj['invalid_sources']){$issues[]='OTJ sources await confirmation or reconciliation';}
            $checks[]=['apprentice_id'=>$a->id,'reference'=>$a->reference,'issues'=>$issues];
            $rows[]=['id'=>$a->id,'reference'=>$a->reference,'name'=>$a->first_name.' '.$a->last_name,'programme'=>$a->programmeLabel(),'tutor'=>$a->tutor?->name,'group'=>$a->groupLabel(),'task_counts'=>['assigned'=>$tasks->count(),'approved'=>$tasks->where('status','Approved')->count(),'awaiting'=>$tasks->where('status','Awaiting marking')->count(),'overdue_marking'=>$tasks->filter(fn($t)=>$t->markingOverdue())->count(),'source_required'=>$tasks->where('status','Source required')->count()],
                'attendance'=>$attendance[$a->id]['report'],'otj'=>$otj,'otj_sources'=>$otjPosition['rows'],'plan_hash'=>$plan['hash']??null,
                'qualifications'=>$qualifications->map(fn($q)=>['id'=>$q->id,'version'=>$q->version,'subject'=>$q->subject,'title'=>$q->title,'level'=>$q->level,'status'=>$q->status,'result'=>$q->result,'target'=>$q->target()?->toDateString(),'position'=>$q->position()])->all(),
                'topics'=>['assigned'=>$topics->filter(fn($t)=>(float)$t->planned_hours>0)->count(),'completed'=>$topics->filter(fn($t)=>(float)$t->planned_hours>0 && $t->status==='Completed')->count()],
                'actions'=>['open'=>$actions->filter(fn($x)=>$x->currentStatus()!=='Completed')->count(),'overdue'=>$actions->filter(fn($x)=>$x->overdue())->count()],
                'reviews'=>['draft'=>$reviews->where('state','Draft')->count(),'ready_for_signatures'=>$reviews->where('state','Ready for signatures')->whereNotIn('id',$confirmedReviewIds)->count(),'confirmed_copies'=>$reviewCycle['confirmed_count'],'cycle'=>$reviewCycle,'completed_history'=>'Confirmed external signed copies only; whole-history compliance not verified'],
                'outcome'=>collect(ProgrammeOutcome::where('apprentice_id',$a->id)->first()?->context??[])->only('status','achievement')->all(),'source_versions'=>['signed_copies'=>$signedRecords->map(fn($r)=>$r->only('id','version','snapshot_hash','checked_at','withdrawn_at'))->all(),'reviews'=>$reviews->map(fn($r)=>$r->only('id','version','state'))->all(),'apprentice'=>$a->version,'tasks'=>$tasks->map(fn($t)=>['id'=>$t->id,'version'=>$t->version,'template_hash'=>$t->template['sha256']??null])->all()]];
        }
        return ['captured_at'=>now()->toIso8601String(),'scope_apprentice_ids'=>$ids,'apprentices'=>$rows,'checks'=>$checks,'summary'=>['apprentices'=>count($rows),'waiting_tasks'=>array_sum(array_column(array_column($rows,'task_counts'),'awaiting')),'overdue_marking'=>array_sum(array_column(array_column($rows,'task_counts'),'overdue_marking')),'overdue_actions'=>array_sum(array_column(array_column($rows,'actions'),'overdue')),'records_with_checks'=>count(array_filter($checks,fn($c)=>count($c['issues'])>0))],
            'limits'=>['Current recorded operational evidence, captured at the stated time; not reconstructed historical performance.','No official retention/achievement rate, Ofsted grade, efficiency score or additional RAG threshold is inferred.','Electronic signing, whole-history review compliance, historic OTJ completeness and formal KSB/gateway assurance remain separate.','Private Careers discussions, support/safeguarding details, evidence files and staff HR are excluded.','Missing requirements and incomplete source records need checking; an empty list is not whole-portal assurance.']];
    }
}
