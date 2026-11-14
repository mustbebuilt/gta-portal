<?php
namespace App\Support;

use App\Models\ProgressReviewDraft;
use App\Models\User;
use Illuminate\Support\Collection;

class ReviewFollowup
{
    public const VIEWS=['all','due','overdue','today','checks','upcoming','paused','assignments'];

    public static function bucket(array $position): string
    {
        if($position['programme_check']){return 'checks';}
        if($position['paused']){return 'paused';}
        $due=$position['cycle']['next_due'];
        if(!$due){return 'checks';}
        return $due<today()->toDateString()?'overdue':($due===today()->toDateString()?'today':'upcoming');
    }
    public static function for(User $user,Collection $people,bool $drafts=false): array
    {
        $people=$people->filter(fn($a)=>SignedReviews::allowed($user,$a));$positions=[];$rows=[];
        $latest=$drafts?ProgressReviewDraft::whereIn('apprentice_id',$people->pluck('id'))->select('id','apprentice_id','state','version','updated_at')->orderByDesc('updated_at')->orderByDesc('id')->get()->groupBy('apprentice_id')->map->first():collect();
        foreach($people as $a){
            $p=ReviewReminders::position($a);$positions[$a->id]=$p;
            $rows[]=['apprentice'=>$a,'bucket'=>self::bucket($p),'due'=>$p['cycle']['next_due'],'assignment_checks'=>$p['due'] && $p['owner_checks']>0,'latest'=>$latest->get($a->id)];
        }
        $rank=['overdue'=>0,'today'=>1,'checks'=>2,'upcoming'=>3,'paused'=>4];usort($rows,fn($a,$b)=>[$rank[$a['bucket']],$a['due']??'',$a['apprentice']->reference,$a['apprentice']->id]<=>[$rank[$b['bucket']],$b['due']??'',$b['apprentice']->reference,$b['apprentice']->id]);$rows=collect($rows);
        $counts=['all'=>$rows->count()];foreach(['overdue','today','checks','upcoming','paused'] as $bucket){$counts[$bucket]=$rows->where('bucket',$bucket)->count();}$counts['due']=$counts['overdue']+$counts['today'];$counts['assignments']=$rows->where('assignment_checks',true)->count();
        return ['rows'=>$rows,'counts'=>$counts,'positions'=>$positions];
    }
    public static function filter(Collection $rows,string $view): Collection
    {
        return match($view){'all'=>$rows,'due'=>$rows->whereIn('bucket',['overdue','today']),'assignments'=>$rows->where('assignment_checks',true),default=>$rows->where('bucket',$view)};
    }
}
