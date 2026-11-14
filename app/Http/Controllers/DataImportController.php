<?php
namespace App\Http\Controllers;

use App\Models\DataImportBatch;
use App\Models\HistoricalOtjRecord;
use App\Support\LearningAccess;
use App\Support\ReviewedImports;
use Illuminate\Http\Request;

class DataImportController extends Controller
{
    private function allowed(Request $r): void {abort_unless(LearningAccess::manager($r->user()),403);}

    public function index(Request $r)
    {
        $this->allowed($r);return view('imports.index',['batches'=>DataImportBatch::orderByDesc('id')->paginate(25)]);
    }
    public function form(Request $r,string $kind)
    {
        $this->allowed($r);$r->validate(['lookup'=>['nullable','string','max:100']]);$lookup=trim((string)$r->query('lookup',''));
        if($kind==='qualification-results'){
            $sources=\App\Models\Qualification::with('apprentice')->whereIn('status',['Working towards','Awaiting confirmation'])->when($lookup!=='',fn($q)=>$q->whereHas('apprentice',fn($p)=>$p->where('reference','like','%'.$lookup.'%')))->orderBy('apprentice_id')->orderBy('id')->paginate(25)->withQueryString();
        }else{
            $sources=\App\Models\Apprentice::with('programmeRecord')->when($lookup!=='',fn($q)=>$q->where('reference','like','%'.$lookup.'%'))->orderBy('reference')->paginate(25)->withQueryString();
        }
        return view('imports.form',compact('kind','sources'));
    }
    public function template(Request $r,string $kind)
    {
        $this->allowed($r);
        return response(implode(',',ReviewedImports::HEADERS[$kind])."\n",200,['Content-Type'=>'text/csv; charset=UTF-8','Content-Disposition'=>'attachment; filename="GTA-'.$kind.'-template.csv"','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }
    public function preview(Request $r,string $kind)
    {
        $this->allowed($r);$data=$r->validate(['file'=>['required','file','extensions:csv','max:1024'],'source_reference'=>['required','string','max:2000']]);
        $batch=ReviewedImports::prepare($kind,$r->file('file'),$data['source_reference'],$r->user());
        return to_route('imports.show',$batch)->with('status','Preview saved. No qualification or OTJ record changed. A different administrator/CEO must check the rows before applying.');
    }
    public function show(Request $r,DataImportBatch $batch)
    {
        $this->allowed($r);return view('imports.show',compact('batch'));
    }
    public function apply(Request $r,DataImportBatch $batch)
    {
        $this->allowed($r);$rules=['version'=>['required','integer'],'preview_hash'=>['required','string','size:64'],'source_checked'=>['accepted']];
        if($batch->kind==='historical-otj'){$rules['occupational_checked']=['accepted'];$rules['duplicate_checked']=['accepted'];}else{$rules['results_checked']=['accepted'];}
        $r->validate($rules);ReviewedImports::apply($batch,$r->user(),$r->integer('version'),$r->input('preview_hash'));
        return back()->with('status','Reviewed import applied atomically. Attendance and training-topic completion were not changed.');
    }
    public function withdraw(Request $r,HistoricalOtjRecord $record)
    {
        $this->allowed($r);$data=$r->validate(['version'=>['required','integer'],'reason'=>['required','string','max:2000']]);
        ReviewedImports::withdraw($record,$r->user(),$data['version'],$data['reason']);
        return back()->with('status','Imported OTJ record withdrawn. Its source and change history are retained; earlier report/review snapshots are unchanged.');
    }
}
