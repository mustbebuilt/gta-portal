<?php
namespace App\Http\Controllers;
use App\Models\HistoricalCohort;
use App\Support\{HistoricalCohorts,HistoricalBenchmarks,PortalPdf};
use Illuminate\Http\Request;
class HistoricalBenchmarkController extends Controller
{
 private function report(Request $r,bool $pin=false):array
 {
  $u=HistoricalCohorts::actor($r->user());$d=$r->validate(['earlier'=>'required|uuid','later'=>'required|uuid','analysis'=>$pin?'required|regex:/^[a-f0-9]{64}$/D':'prohibited','weights'=>'prohibited','minimum'=>'prohibited','staff'=>'prohibited','from'=>'prohibited','to'=>'prohibited','as_of'=>'prohibited']);
  $report=HistoricalBenchmarks::capture($u,HistoricalCohort::findOrFail($d['earlier']),HistoricalCohort::findOrFail($d['later']));
  abort_if($pin&&!hash_equals($report['analysis_hash'],$d['analysis']),409,'The approved comparison changed. Reopen the report before downloading.');return $report;
 }
 public function show(Request $r){$report=$this->report($r);return response()->view('historical-cohorts.benchmark',compact('report'))->header('Cache-Control','private, no-store')->header('Referrer-Policy','no-referrer');}
 public function download(Request $r){$report=$this->report($r,true);return response()->json($report,200,['Content-Disposition'=>'attachment; filename="GTA-Historical-Same-Mix.json"','Cache-Control'=>'private, no-store','Referrer-Policy'=>'no-referrer','X-Content-Type-Options'=>'nosniff']);}
 public function pdf(Request $r){$report=$this->report($r,true);return PortalPdf::response('pdf.historical-benchmark',compact('report'),'GTA-Historical-Same-Mix')->header('Referrer-Policy','no-referrer');}
}
