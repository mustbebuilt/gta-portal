<?php
namespace App\Http\Controllers;
use App\Models\EvidenceRestoreRun;
use App\Support\EvidenceRestore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Gate};
class EvidenceRestoreController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('manage-directories');
        $data=$request->validate(['state'=>['nullable','in:all,Prepared,Running,Verified restore rehearsal,Needs checks']]);$state=$data['state']??'all';
        $runs=EvidenceRestoreRun::when($state!=='all',fn($q)=>$q->where('state',$state))->orderByDesc('created_at')->orderBy('id')->paginate(20)->withQueryString();
        return view('evidence-restore.index',compact('runs','state'));
    }
    public function show(EvidenceRestoreRun $run)
    {
        Gate::authorize('manage-directories');
        return view('evidence-restore.show',['summary'=>EvidenceRestore::summary($run),'events'=>DB::table('evidence_restore_events')->where('run_id',$run->id)->orderBy('id')->get(['event','created_at'])]);
    }
}
