<?php
namespace App\Support;
use App\Models\{User,ProgressReviewDraft,SupportPlan,IndividualDeliveryPlan};
use Illuminate\Support\Collection;

class SavedPortalWork
{
    public const KINDS=['all'=>'All saved work','review'=>'Review drafts','support'=>'Support-plan drafts','delivery'=>'Delivery plans awaiting approval'];
    public const LIMIT=200;
    public static function allowed(User $u):bool{return $u->active&&$u->role!=='apprentice'&&(PortalActions::allowed($u,'reviews')||PortalActions::allowed($u,'support')||PortalActions::allowed($u,'delivery'));}
    public static function read(User $u,string $kind='all',string $search=''):array
    {
        abort_unless(self::allowed($u),403);abort_unless(isset(self::KINDS[$kind]),422);
        $items=collect();$coverage=[];
        foreach(['review'=>[ProgressReviewDraft::class,'reviews'],'support'=>[SupportPlan::class,'support'],'delivery'=>[IndividualDeliveryPlan::class,'delivery']]as$type=>[$model,$task]){
            if(($kind!=='all'&&$kind!==$type)||!PortalActions::allowed($u,$task)){continue;}
            $table=(new $model)->getTable();
            $query=$model::query()->join('apprentices','apprentices.id','=',$table.'.apprentice_id')->whereIn('apprentices.id',PortalActions::people($u,$task)->select('apprentices.id'));
            if($type==='review'){$query->where($table.'.state','Draft');}
            elseif($type==='support'){$query->where($table.'.status','Draft');}
            else{$query->where($table.'.active',false)->whereNotNull($table.'.draft');}
            if($search!==''){$p=\App\Http\Controllers\PortalActionsController::pattern($search);$query->where(fn($q)=>$q->whereRaw('LOWER(apprentices.reference) LIKE ? ESCAPE \'!\'',[$p])->orWhereRaw('LOWER(apprentices.first_name) LIKE ? ESCAPE \'!\'',[$p])->orWhereRaw('LOWER(apprentices.last_name) LIKE ? ESCAPE \'!\'',[$p]));}
            $matched=(clone $query)->count();
            $rows=$query->orderByDesc($table.'.updated_at')->orderByDesc($table.'.id')->limit(self::LIMIT)->get([$table.'.id',$table.'.apprentice_id',$table.'.updated_at','apprentices.reference','apprentices.first_name','apprentices.last_name']);
            $coverage[$type]=['matched'=>$matched,'shown'=>$rows->count(),'limited'=>$matched>$rows->count()];
            foreach($rows as$row){
                $url=match($type){'review'=>route('reviews.edit',[$row->apprentice_id,$row->id]),'support'=>route('support.plan',[$row->apprentice_id,$row->id]),'delivery'=>route('delivery.show',$row->apprentice_id)};
                $items->push(['kind'=>$type,'label'=>self::KINDS[$type],'id'=>$row->id,'apprentice_id'=>$row->apprentice_id,'reference'=>$row->reference,'name'=>$row->first_name.' '.$row->last_name,'saved_at'=>$row->updated_at?->toIso8601String(),'url'=>$url]);
            }
        }
        // Stable ordering across different source tables, including equal timestamps.
        $items=$items->sort(fn($a,$b)=>strcmp($b['saved_at']??'',$a['saved_at']??'')?:strcmp($a['kind'],$b['kind'])?:($b['id']<=>$a['id']))->values();
        return ['items'=>$items,'coverage'=>$coverage,'limited'=>collect($coverage)->contains(fn($row)=>$row['limited'])];
    }
}
