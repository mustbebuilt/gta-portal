<?php
namespace App\Http\Controllers;
use App\Models\{Apprentice,SourceReconciliation,User};
use App\Support\{CurriculumSources,LearningAccess,SourceReconciliations,TaskCatalogue};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Gate};
use Illuminate\Validation\Rule;
class CurriculumSourceController extends Controller
{
    public function index()
    {
        Gate::authorize('manage-directories');$releases=[];foreach(array_keys(TaskCatalogue::PROGRAMMES)as$key){$releases[]=CurriculumSources::safeRelease(CurriculumSources::release($key));}return view('curriculum-sources.index',compact('releases'));
    }
    public function show(Request $request,Apprentice $apprentice)
    {
        abort_unless(CurriculumSources::allowed($request->user(),$apprentice),403);$audit=CurriculumSources::apprentice($apprentice);
        $records=SourceReconciliation::where('apprentice_id',$apprentice->id)->orderByDesc('id')->paginate(10)->withQueryString();return view('curriculum-sources.apprentice',compact('apprentice','audit','records'));
    }
    public function submit(Request $request,Apprentice $apprentice)
    {
        abort_unless(CurriculumSources::allowed($request->user(),$apprentice),403);
        $data=$request->validate(['binding'=>['required','regex:/^[a-f0-9]{64}$/'],'decision'=>['required',Rule::in(array_keys(SourceReconciliations::DECISIONS))],'source_reference'=>['required','string','max:1000'],'scope_note'=>['required','string','max:1500'],'reason'=>['required','string','max:2000'],'confirmed'=>['required','accepted']]);
        $record=SourceReconciliations::submit($apprentice,$request->user(),$request->session()->get('auth_version'),$data);return to_route('curriculum-sources.record',[$apprentice,$record])->with('status','Source assessment recorded for independent review. No task, source warning or training plan was changed.');
    }
    public function record(Request $request,Apprentice $apprentice,SourceReconciliation $record)
    {
        abort_unless(CurriculumSources::allowed($request->user(),$apprentice),403);abort_unless($record->apprentice_id===$apprentice->id,404);
        $audit=CurriculumSources::apprentice($apprentice);$events=DB::table('source_reconciliation_events')->where('reconciliation_id',$record->id)->orderBy('id')->get();$actors=User::whereIn('id',[$record->author_id,$record->reviewer_id,...$events->pluck('actor_id')->all()])->get(['id','name'])->keyBy('id');return view('curriculum-sources.record',['apprentice'=>$apprentice,'record'=>$record,'current'=>SourceReconciliations::current($record,$audit),'manager'=>LearningAccess::manager($request->user()),'events'=>$events,'actors'=>$actors]);
    }
    public function review(Request $request,Apprentice $apprentice,SourceReconciliation $record)
    {
        abort_unless(LearningAccess::manager($request->user()),403);abort_unless($record->apprentice_id===$apprentice->id,404);
        $data=$request->validate(['version'=>['required','integer'],'action'=>['required','in:record,return,withdraw'],'note'=>['required','string','max:2000'],'confirmed'=>['required','accepted']]);
        SourceReconciliations::review($record,$request->user(),$request->session()->get('auth_version'),$data['version'],$data['action'],$data['note']);return to_route('curriculum-sources.record',[$apprentice,$record])->with('status','Review history updated. No source warning was cleared and no tasks or funding decisions were changed.');
    }
}
