<?php
namespace App\Http\Controllers;
use App\Support\AssignmentChecks;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AssignmentCheckController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('manage-directories');
        $data=$request->validate(['search'=>['nullable','string','max:100'],'role'=>['nullable',Rule::in(['all',...array_keys(AssignmentChecks::ROLES)])],
            'staff'=>['nullable','integer',Rule::exists('users','id')],'view'=>['nullable',Rule::in(['checks','all','unassigned'])],'page'=>['nullable','integer','min:1','max:1000000']]);
        $filters=['search'=>$data['search']??'','role'=>$data['role']??'all','staff'=>$data['staff']??null,'view'=>$data['view']??'checks'];
        $snapshot=AssignmentChecks::snapshot();$result=AssignmentChecks::filter($snapshot['rows'],$filters);
        $page=min((int)($data['page']??1),max(1,(int)ceil($result['rows']->count()/20)));
        $rows=new LengthAwarePaginator($result['rows']->slice(($page-1)*20,20)->values(),$result['rows']->count(),20,$page,['path'=>$request->url(),'query'=>$request->except('page')]);
        return response()->view('directories.assignment-checks',['rows'=>$rows,'counts'=>$result['counts'],'staff'=>$snapshot['staff'],'filters'=>$filters])
            ->header('Cache-Control','no-store, private');
    }
}
