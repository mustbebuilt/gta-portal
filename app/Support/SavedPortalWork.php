<?php
namespace App\Support;
use App\Models\{Apprentice,ApprenticeSetupDraft68,User,ProgressReviewDraft,SupportPlan,IndividualDeliveryPlan};
use Illuminate\Support\Collection;

class SavedPortalWork
{
    public const KINDS=['all'=>'All saved work','setup'=>'Apprentice setup drafts','review'=>'Review drafts','support'=>'Support-plan drafts','delivery'=>'Delivery plans awaiting approval'];
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
            else{$query->whereNotNull($table.'.draft');}
            if($search!==''){$p=\App\Http\Controllers\PortalActionsController::pattern($search);$query->where(fn($q)=>$q->whereRaw('LOWER(apprentices.reference) LIKE ? ESCAPE \'!\'',[$p])->orWhereRaw('LOWER(apprentices.first_name) LIKE ? ESCAPE \'!\'',[$p])->orWhereRaw('LOWER(apprentices.last_name) LIKE ? ESCAPE \'!\'',[$p]));}
            $fields = [$table.'.id', $table.'.apprentice_id', $table.'.updated_at', 'apprentices.reference', 'apprentices.first_name', 'apprentices.last_name'];
            if ($type === 'delivery' && LearningAccess::manager($u)) {
                [$rows, $coverage[$type]] = self::deliveryRevisions($query, $table, $fields);
            } else {
                if ($type === 'delivery') {
                    $query->where($table.'.active', false);
                }
                $matched = (clone $query)->count();
                $rows = $query->orderByDesc($table.'.updated_at')->orderByDesc($table.'.id')->limit(self::LIMIT)->get($fields);
                $coverage[$type] = ['matched'=>$matched, 'shown'=>$rows->count(), 'limited'=>$matched>$rows->count()];
            }
            foreach($rows as$row){
                $url=match($type){'review'=>route('reviews.edit',[$row->apprentice_id,$row->id]),'support'=>route('support.plan',[$row->apprentice_id,$row->id]),'delivery'=>route('delivery.show',$row->apprentice_id)};
                $items->push(['kind'=>$type,'label'=>self::KINDS[$type],'id'=>$row->id,'apprentice_id'=>$row->apprentice_id,'reference'=>$row->reference,'name'=>$row->first_name.' '.$row->last_name,'saved_at'=>$row->updated_at?->toIso8601String(),'url'=>$url]);
            }
        }
        if (in_array($kind, ['all', 'setup'], true) && $u->can('create', Apprentice::class)) {
            [$setup, $coverage['setup']] = self::setup($search);
            $items = $items->concat($setup);
        }
        // Stable ordering across different source tables, including equal timestamps.
        $items=$items->sort(fn($a,$b)=>strcmp($b['saved_at']??'',$a['saved_at']??'')?:strcmp($a['kind'],$b['kind'])?:($b['id']<=>$a['id']))->values();
        return ['items'=>$items,'coverage'=>$coverage,'limited'=>collect($coverage)->contains(fn($row)=>$row['limited'])];
    }

    private static function deliveryRevisions(\Illuminate\Database\Eloquent\Builder $query, string $table, array $fields): array
    {
        $revisions = $query->orderByDesc($table.'.updated_at')->orderByDesc($table.'.id')
            ->select([...$fields, $table.'.active', $table.'.draft', $table.'.approved->draft_hash as approved_draft_hash'])
            ->cursor()->filter(fn (IndividualDeliveryPlan $plan) => !$plan->active ||
                ($plan->approved_draft_hash && $plan->approved_draft_hash !== DeliveryPlan::draftHash($plan->draft)));

        return self::limited($revisions);
    }

    private static function setup(string $search): array
    {
        // Use the same admin scope as the setup list. Draft details are encrypted.
        $items = ApprenticeSetupDraft68::whereIn('state', ['Draft', 'Ready'])
            ->orderByDesc('updated_at')->orderByDesc('id')->cursor()
            ->map(function (ApprenticeSetupDraft68 $draft): array {
                $details = $draft->payload['details'] ?? [];

                return [
                    'kind' => 'setup',
                    'label' => self::KINDS['setup'],
                    'id' => $draft->id,
                    'apprentice_id' => $draft->apprentice_id,
                    'reference' => $details['reference'] ?? '',
                    'name' => trim(($details['first_name'] ?? '').' '.($details['last_name'] ?? '')) ?: 'New apprentice',
                    'saved_at' => $draft->updated_at?->toIso8601String(),
                    'url' => route('setup68.show', $draft),
                ];
            })->filter(fn (array $item) => $search === '' ||
                mb_stripos($item['name'].' '.$item['reference'], $search) !== false);
        return self::limited($items);
    }

    private static function limited(iterable $items): array
    {
        $matched = 0;
        $visible = collect();
        foreach ($items as $item) {
            if (++$matched <= self::LIMIT) {
                $visible->push($item);
            }
        }

        return [$visible, [
            'matched' => $matched, 'shown' => $visible->count(), 'limited' => $matched > $visible->count(),
        ]];
    }
}
