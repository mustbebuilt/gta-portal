<?php
namespace App\Http\Controllers;
use App\Support\{AssignmentChecks,MyResponsibilities};
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MyResponsibilitiesController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(MyResponsibilities::allowed($request->user()),403);
        $data=$request->validate(['search'=>['nullable','string','max:100'],'role'=>['nullable',Rule::in(['all',...array_keys(AssignmentChecks::ROLES)])],'page'=>['nullable','integer','min:1','max:1000000']]);
        $summary=MyResponsibilities::summary($request->user());$role=$data['role']??'all';$search=trim($data['search']??'');
        abort_unless($role==='all'||isset($summary['roles'][$role]),403);
        $filtered=$summary['rows']->filter(function($row) use($role,$search) {
            $a=$row['apprentice'];
            return ($role==='all'||isset($row['duties'][$role]))&&($search===''||Str::contains(Str::lower($a->reference.' '.$a->first_name.' '.$a->last_name),Str::lower($search)));
        })->values();
        $page=min((int)($data['page']??1),max(1,(int)ceil($filtered->count()/20)));
        $rows=new LengthAwarePaginator($filtered->slice(($page-1)*20,20)->values(),$filtered->count(),20,$page,['path'=>$request->url(),'query'=>$request->except('page')]);
        return response()->view('responsibilities.index',['rows'=>$rows,'roles'=>$summary['roles'],'counts'=>$summary['counts'],'total'=>$summary['total'],'selectedRole'=>$role,'search'=>$search])
            ->header('Cache-Control','no-store, private');
    }
}
