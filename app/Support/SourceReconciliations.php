<?php
namespace App\Support;
use App\Models\{Apprentice,SourceReconciliation,User};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class SourceReconciliations
{
    public const DECISIONS=['retain'=>'Staff assessment: retain saved task sources','investigate'=>'Source decision or manual investigation required'];
    private static function fail(string $message): never {throw ValidationException::withMessages(['source_review'=>$message]);}
    private static function event(SourceReconciliation $row,User $actor,string $event,?string $note=null): void {DB::table('source_reconciliation_events')->insert(['reconciliation_id'=>$row->id,'actor_id'=>$actor->id,'event'=>$event,'note'=>$note,'created_at'=>now()]);}
    public static function current(SourceReconciliation $row,array $audit): bool
    {
        return is_array($row->snapshot) && hash_equals(ReviewedImports::hash($row->snapshot),$row->binding) && hash_equals($audit['binding'],$row->binding);
    }
    public static function submit(Apprentice $a,User $actor,int $authVersion,array $data): SourceReconciliation
    {
        if(!array_key_exists($data['decision']??'',self::DECISIONS)){self::fail('Choose a listed source assessment.');}
        return DB::transaction(function()use($a,$actor,$authVersion,$data){
            $a=Apprentice::whereKey($a->id)->lockForUpdate()->firstOrFail();$u=User::whereKey($actor->id)->lockForUpdate()->firstOrFail();abort_unless($u->auth_version===$authVersion && CurriculumSources::allowed($u,$a),403);
            $audit=CurriculumSources::apprentice($a);if($audit['recorded_count']>200){self::fail('The source set exceeds 200 records. Reconcile it before recording an assessment.');}if(!hash_equals($audit['binding'],$data['binding'])){self::fail('The cohort or task source changed. Reopen the comparison before recording a decision.');}
            if(($data['decision']??null)==='retain' && (!$audit['scope_ok'] || $audit['missing_count'] || array_sum(array_intersect_key($audit['counts'],array_flip(['invalid','unexpected','unrecognised']))))){self::fail('Resolve technical, mapping, missing or unrecognised source checks first. You can record an investigation requirement.');}
            $row=SourceReconciliation::create(['apprentice_id'=>$a->id,'author_id'=>$u->id,'snapshot'=>$audit['snapshot'],'binding'=>$audit['binding'],'decision'=>$data['decision'],'source_reference'=>$data['source_reference'],'scope_note'=>$data['scope_note'],'reason'=>$data['reason']]);self::event($row,$u,'submitted');return $row;
        });
    }
    public static function review(SourceReconciliation $row,User $actor,int $authVersion,int $version,string $action,string $note): void
    {
        if(trim($note)==='' || mb_strlen($note)>2000){self::fail('Record a review or withdrawal reason, up to 2000 characters.');}
        DB::transaction(function()use($row,$actor,$authVersion,$version,$action,$note){
            $a=Apprentice::whereKey($row->apprentice_id)->lockForUpdate()->firstOrFail();$u=User::whereKey($actor->id)->lockForUpdate()->firstOrFail();$row=SourceReconciliation::whereKey($row->id)->lockForUpdate()->firstOrFail();abort_unless($u->auth_version===$authVersion && LearningAccess::manager($u),403);
            if($row->version!==$version || $row->state==='Withdrawn'){self::fail('This record changed or has been withdrawn. Reload its history.');}
            if($action==='withdraw'){$row->state='Withdrawn';}
            else{
                abort_unless(in_array($action,['record','return'],true) && $u->id!==$row->author_id,403);
                if($row->state!=='Submitted'){self::fail('This submitted assessment has already been reviewed. Record a new assessment or withdraw it.');}
                if($action==='record' && !self::current($row,CurriculumSources::apprentice($a))){self::fail('The current source comparison differs from this submitted snapshot. Return it for investigation; do not approve an obsolete binding.');}
                $row->state=$action==='record'?'Review recorded':'Returned';$row->reviewer_id=$u->id;$row->review_note=$note;$row->reviewed_at=now();
            }
            $row->version++;$row->save();self::event($row,$u,$row->state,$note);
        });
    }
}
