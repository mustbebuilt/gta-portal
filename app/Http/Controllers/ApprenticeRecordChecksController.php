<?php
namespace App\Http\Controllers;
use App\Models\Apprentice;
use App\Support\{PortalActions,ApprenticeRecordChecks};
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApprenticeRecordChecksController extends Controller
{
    public function index(Request $r)
    {
        $u=PortalActions::actor($r->user());abort_unless(ApprenticeRecordChecks::allowed($u),403);
        $data=$r->validate(['search'=>['nullable','string','max:100'],'check'=>['nullable','string',Rule::in(array_keys(ApprenticeRecordChecks::FILTERS))],'page'=>['nullable','integer','min:1','max:1000000']]);
        $search=trim($data['search']??'');$check=$data['check']??'all';
        $query=Apprentice::visibleTo($u)->select('id','reference','first_name','last_name','programme_id','employer_id','tutor_id','tutor_group_id','user_id','start_date','planned_end_date');
        if($search!==''){$p=PortalActionsController::pattern($search);$query->where(fn($q)=>$q->whereRaw('LOWER(reference) LIKE ? ESCAPE \'!\'',[$p])->orWhereRaw('LOWER(first_name) LIKE ? ESCAPE \'!\'',[$p])->orWhereRaw('LOWER(last_name) LIKE ? ESCAPE \'!\'',[$p]));}
        if($check==='dates'){$query->where(fn($q)=>$q->whereNull('start_date')->orWhereNull('planned_end_date'));}
        elseif($check!=='all'){$field=['programme'=>'programme_id','tutor'=>'tutor_id','employer'=>'employer_id','group'=>'tutor_group_id','account'=>'user_id'][$check];$query->whereNull($field);}
        $records=$query->orderBy('last_name')->orderBy('first_name')->orderBy('id')->paginate(20)->withQueryString();
        return response()->view('record-checks.index',compact('records','search','check'))->header('Cache-Control','private, no-store')->header('Referrer-Policy','no-referrer');
    }
    public function show(Request $r,Apprentice $apprentice)
    {
        $u=PortalActions::actor($r->user());$checks=ApprenticeRecordChecks::rows($u,$apprentice);
        $identity=$apprentice->only('id','reference','first_name','last_name');
        return response()->view('record-checks.show',compact('identity','checks'))->header('Cache-Control','private, no-store')->header('Referrer-Policy','no-referrer');
    }
}
