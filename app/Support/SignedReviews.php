<?php
namespace App\Support;

use App\Models\Apprentice;
use App\Models\IndividualDeliveryPlan;
use App\Models\ProgressReviewDraft;
use App\Models\SignedReviewRecord;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SignedReviews
{
    public static function allowed(User $u,Apprentice $a): bool
    {
        return $u->active && (LearningAccess::manager($u) || LearningAccess::tutor($u,$a) || ($u->role==='mentor' && $a->mentor_id===$u->id));
    }
    private static function fail(string $text): never {throw ValidationException::withMessages(['version'=>$text]);}
    private static function profile(Apprentice $a): string
    {
        return ReviewedImports::hash($a->only('id','reference','first_name','last_name','programme_id','employer_id','employer','start_date','mentor_id','tutor_id'));
    }
    public static function reviewHash(ProgressReviewDraft $r): string
    {
        return ReviewedImports::hash(['id'=>$r->id,'version'=>$r->version,'fields'=>$r->fields,'source_snapshot'=>$r->source_snapshot]);
    }
    public static function intact(SignedReviewRecord $r): bool
    {
        return ($r->snapshot['apprentice_id']??null)===$r->apprentice_id && ($r->snapshot['review_id']??null)===$r->progress_review_draft_id && !$r->meeting_date->gt(today()) && ReviewedImports::hash($r->snapshot)===$r->snapshot_hash && ($r->file['sha256']??null)===($r->snapshot['file_sha256']??null) && ($r->snapshot['review_version']??null)===$r->review_version && ($r->snapshot['fields']['reviewDate']??null)===$r->meeting_date->toDateString();
    }
    private static function current(Apprentice $a,ProgressReviewDraft $r): void
    {
        $plan=DeliveryPlan::approved($a);
        if($r->state!=='Ready for signatures' || !$a->start_date || !filled($r->fields['reviewDate']??null) || $r->fields['reviewDate']<$a->start_date->toDateString() || $r->fields['reviewDate']>today()->toDateString() || !$plan || ($r->source_snapshot['delivery_plan']['hash']??null)!==$plan['hash']){
            self::fail('Use a frozen ready revision with an actual meeting on/after known first learning and the current provider-approved plan.');
        }
    }
    private static function name(string $v): string {return mb_strtolower(preg_replace('/\s+/u',' ',trim($v)));}

    public static function prepare(ProgressReviewDraft $review,User $actor,UploadedFile $file,array $data): SignedReviewRecord
    {
        $entry=null;
        try{
            return DB::transaction(function() use($review,$actor,$file,$data,&$entry){
                $u=User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $a=Apprentice::whereKey($review->apprentice_id)->lockForUpdate()->firstOrFail();abort_unless(self::allowed($u,$a),403);
                if($a->programme_id){$a->programmeRecord()->lockForUpdate()->first();}IndividualDeliveryPlan::where('apprentice_id',$a->id)->lockForUpdate()->first();
                $r=ProgressReviewDraft::whereKey($review->id)->lockForUpdate()->firstOrFail();
                if($r->version!==(int)$data['version']){self::fail('This frozen review changed. Reopen its current evidence page.');}self::current($a,$r);
                if(SignedReviewRecord::where('progress_review_draft_id',$r->id)->whereNull('withdrawn_at')->exists()){self::fail('A current signed-copy record already exists. Resolve it before adding another.');}
                $expected=['apprentice'=>$a->first_name.' '.$a->last_name,'employer'=>$r->fields['employerRep']??'','mentor'=>$r->fields['mentor']??''];
                foreach($expected as $role=>$name){
                    $signed=$data[$role.'_name'];$date=$data[$role.'_date'];
                    if(!filled($name) || in_array(self::name($signed),['n/a','unknown','not recorded'],true) || self::name($signed)!==self::name($name) || $date<$r->fields['reviewDate'] || $date>today()->toDateString()){
                        self::fail('Match all three signatories to the frozen participant names; signature dates must be on/after the meeting and not future. Correct source discrepancies before recording.');
                    }
                }
                $entry=PrivateEvidence::store($file,'signed-review-evidence',$u->id);
                $snapshot=['review_id'=>$r->id,'review_version'=>$r->version,'review_hash'=>self::reviewHash($r),'apprentice_id'=>$a->id,'reference'=>$a->reference,'apprentice_name'=>$a->first_name.' '.$a->last_name,'employer'=>$a->employerLabel(),'profile_binding'=>self::profile($a),'fields'=>$r->fields,'source_snapshot'=>$r->source_snapshot,'signatories'=>collect($data)->only('apprentice_name','apprentice_date','employer_name','employer_date','mentor_name','mentor_date')->all(),'source_reference'=>$data['source_reference'],'identity_reference'=>$data['identity_reference'],'prepared_name'=>$u->name,'file_sha256'=>$entry['sha256']];
                $record=SignedReviewRecord::create(['apprentice_id'=>$a->id,'progress_review_draft_id'=>$r->id,'review_version'=>$r->version,'meeting_date'=>$r->fields['reviewDate'],'snapshot'=>$snapshot,'snapshot_hash'=>ReviewedImports::hash($snapshot),'file'=>$entry,'prepared_by'=>$u->id,'history'=>[['event'=>'copy_prepared','by'=>$u->id,'at'=>now()->toIso8601String()]]]);
                self::event($record,$u,'copy_prepared');return $record;
            });
        }catch(\Throwable $e){if($entry){PrivateEvidence::delete($entry);}throw $e;}
    }

    public static function confirm(SignedReviewRecord $record,User $actor,int $version): void
    {
        abort_unless(LearningAccess::manager($actor),403);
        DB::transaction(function() use($record,$actor,$version): void {
            $u=User::whereKey($actor->id)->lockForUpdate()->firstOrFail();abort_unless(LearningAccess::manager($u),403);
            $a=Apprentice::whereKey($record->apprentice_id)->lockForUpdate()->firstOrFail();
            if($a->programme_id){$a->programmeRecord()->lockForUpdate()->first();}IndividualDeliveryPlan::where('apprentice_id',$a->id)->lockForUpdate()->first();
            $r=ProgressReviewDraft::whereKey($record->progress_review_draft_id)->lockForUpdate()->firstOrFail();$row=SignedReviewRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
            if($row->version!==$version || $row->checked_at || $row->withdrawn_at || $row->prepared_by===$u->id){self::fail('A different administrator/CEO must check the current unwithdrawn pending copy once.');}
            self::current($a,$r);
            if(SignedReviewRecord::where('apprentice_id',$a->id)->whereDate('meeting_date',$row->meeting_date->toDateString())->whereNotNull('checked_at')->whereNull('withdrawn_at')->where('id','!=',$row->id)->exists()){self::fail('A checked signed review already exists for this apprentice and meeting date. Reconcile the duplicate source first.');}
            if(!self::intact($row) || self::reviewHash($r)!==$row->snapshot['review_hash'] || self::profile($a)!==$row->snapshot['profile_binding'] || !PrivateEvidence::matchesHash($row->file)){
                self::fail('The frozen review, participant mapping or signed file changed/is unavailable. Reconcile the source before confirming.');
            }
            $row->checked_at=now();$row->checked_by=$u->id;$row->version++;
            $row->history=[...$row->history,['event'=>'signed_copy_confirmed','by'=>$u->id,'name'=>$u->name,'at'=>now()->toIso8601String()]];$row->save();self::event($row,$u,'signed_copy_confirmed');
        });
    }

    public static function withdraw(SignedReviewRecord $record,User $actor,int $version,string $reason): void
    {
        abort_unless(LearningAccess::manager($actor),403);
        DB::transaction(function() use($record,$actor,$version,$reason): void {
            $u=User::whereKey($actor->id)->lockForUpdate()->firstOrFail();abort_unless(LearningAccess::manager($u),403);
            Apprentice::whereKey($record->apprentice_id)->lockForUpdate()->firstOrFail();ProgressReviewDraft::whereKey($record->progress_review_draft_id)->lockForUpdate()->firstOrFail();
            $row=SignedReviewRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
            if($row->version!==$version || $row->withdrawn_at){self::fail('This evidence record changed or is already withdrawn.');}
            $row->withdrawn_at=now();$row->version++;$row->history=[...$row->history,['event'=>'copy_withdrawn','by'=>$u->id,'reason'=>$reason,'at'=>now()->toIso8601String()]];$row->save();self::event($row,$u,'copy_withdrawn');
        });
    }

    public static function cycle(Apprentice $a): array
    {
        $records=SignedReviewRecord::where('apprentice_id',$a->id)->whereNotNull('checked_at')->whereNull('withdrawn_at')->orderByDesc('meeting_date')->orderByDesc('id')->get();
        $invalid=$records->filter(fn($r)=>!self::intact($r))->count();$latest=$records->first(fn($r)=>self::intact($r));
        $due=$latest && !$invalid ? $latest->meeting_date->copy()->addDays(70) : null;
        return ['confirmed_count'=>$records->count()-$invalid,'integrity_checks'=>$invalid,'latest_record_id'=>$latest?->id,'last_meeting'=>$latest?->meeting_date->toDateString(),'next_due'=>$due?->toDateString(),'status'=>$invalid?'Evidence integrity needs checking':(!$due?'History to check':($due->lt(today())?'Overdue tracked reference':($due->isToday()?'Due today':'Future tracked reference'))),'first_review_reference'=>$a->start_date?->copy()->addDays(70)->toDateString(),'basis'=>$due?'Latest unwithdrawn independently checked signed copy in this portal':'No reliable completed-copy reference; check historical records','history_completeness'=>'Not verified; no whole-cohort compliance rate inferred'];
    }

    private static function event(SignedReviewRecord $r,User $u,string $event): void
    {
        DB::table('signed_review_events')->insert(['signed_review_record_id'=>$r->id,'actor_id'=>$u->id,'event'=>$event,'created_at'=>now()]);
    }
}
