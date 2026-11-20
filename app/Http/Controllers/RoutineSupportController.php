<?php
namespace App\Http\Controllers;

use App\Models\{Apprentice,SupportPlan};
use App\Support\{RoutineSupport,SupportAccess};
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoutineSupportController extends Controller
{
    public function show(Request $r, Apprentice $apprentice, ?SupportPlan $plan=null)
    {
        abort_unless(SupportAccess::team($r->user(),$apprentice),403);
        if ($plan) {abort_unless($plan->apprentice_id===$apprentice->id && RoutineSupport::is($plan),404);}
        $current=$plan??SupportPlan::where('apprentice_id',$apprentice->id)->where('status','!=','Closed')->first();
        if (!$plan && $current) {
            return RoutineSupport::is($current) ? to_route('routine-support.show',[$apprentice,$current]) : to_route('support.plan',[$apprentice,$current]);
        }
        return view('support.routine',['apprentice'=>$apprentice,'plan'=>$plan,'owners'=>SupportAccess::owners($apprentice)]);
    }

    public function save(Request $r, Apprentice $apprentice, ?SupportPlan $plan=null)
    {
        abort_unless(SupportAccess::team($r->user(),$apprentice),403);
        if ($plan) {abort_unless($plan->apprentice_id===$apprentice->id && RoutineSupport::is($plan),404);}
        $d=$r->validate(['version'=>['required','integer','min:0'],'profile_version'=>['required','integer','min:1'],
            'request_id'=>['required','uuid'],'intent'=>['required',Rule::in(['draft','activate'])],
            'need'=>['required','string','max:10000'],'action'=>['required','string','max:10000'],
            'measure'=>['nullable','string','max:5000'],'apprentice_view'=>['nullable','string','max:10000'],
            'owner_id'=>['required','integer',Rule::in(SupportAccess::owners($apprentice)->pluck('id')->all())],
            'review'=>['required','date_format:Y-m-d','after_or_equal:today'],
            'change_reason'=>['nullable','string','max:2000'],'funding'=>['prohibited'],'code'=>['prohibited'],'concern_ids'=>['prohibited']]);
        $p=RoutineSupport::save($r->user(),(int)$r->session()->get('auth_version'),$apprentice,$plan,$d);
        return to_route('routine-support.show',[$apprentice,$p])->with('status',$p->status==='Active'?'Routine support saved and active. No marker, funding claim, OTJ or employer sharing was created.':'Private draft saved. It is not active support.');
    }
}
