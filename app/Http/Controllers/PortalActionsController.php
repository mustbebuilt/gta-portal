<?php
namespace App\Http\Controllers;
use App\Models\Apprentice;
use App\Support\PortalActions;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PortalActionsController extends Controller
{
    public function index(Request $r)
    {
        $u=PortalActions::actor($r->user());
        $data=$r->validate(['task'=>['nullable','string',Rule::in(array_keys(PortalActions::TASKS))],'q'=>['nullable','string','max:100'],'search'=>['nullable','string','max:100'],'page'=>['nullable','integer','min:1','max:1000000']]);
        $task=$data['task']??null;$q=trim($data['q']??'');$search=trim($data['search']??'');
        $cards=collect(PortalActions::TASKS)->filter(fn($row,$key)=>PortalActions::allowed($u,$key))->filter(fn($row)=>$q===''||str_contains(mb_strtolower($row['title'].' '.$row['body'].' '.$row['keywords']),mb_strtolower($q)));
        $globals=collect(PortalActions::globals($u))->filter(fn($row)=>$q===''||str_contains(mb_strtolower($row['title'].' '.$row['body']),mb_strtolower($q)));
        $people=null;$destinations=[];
        if($task){
            $query=PortalActions::people($u,$task)->select('id','reference','first_name','last_name');
            // Literal substring search: SQL wildcards entered by a user are not a request for every record.
            if($search!==''){$query->where(function($q)use($search){$q->whereRaw('LOWER(reference) LIKE ? ESCAPE \'!\'',[self::pattern($search)])->orWhereRaw('LOWER(first_name) LIKE ? ESCAPE \'!\'',[self::pattern($search)])->orWhereRaw('LOWER(last_name) LIKE ? ESCAPE \'!\'',[self::pattern($search)]);});}
            $people=$query->orderBy('last_name')->orderBy('first_name')->orderBy('id')->paginate(20)->withQueryString();
            foreach($people as$a){$destinations[$a->id]=PortalActions::destination($u,$a,$task);}
        }
        return response()->view('quick-actions.index',compact('cards','globals','q','search','task','people','destinations'))->header('Cache-Control','private, no-store')->header('Referrer-Policy','no-referrer');
    }
    public static function pattern(string $s):string {return '%'.str_replace(['!','%','_'],['!!','!%','!_'],mb_strtolower($s)).'%';}
    public function person(Request $r,Apprentice $apprentice)
    {
        $u=PortalActions::actor($r->user());$cards=PortalActions::forPerson($u,$apprentice);abort_unless($cards,403);
        $identity=$apprentice->only('id','reference','first_name','last_name');
        return response()->view('quick-actions.person',compact('identity','cards'))->header('Cache-Control','private, no-store')->header('Referrer-Policy','no-referrer');
    }
}
