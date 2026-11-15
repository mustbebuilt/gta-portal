<?php
namespace App\Http\Controllers;
use App\Support\{PortalVisualReport,PortalPdf};
use Illuminate\Http\Request;
class PortalVisualReportController extends Controller
{
 public function index(Request $r){$report=PortalVisualReport::capture($r);return response()->view('visual-reports.index',compact('report'))->header('Cache-Control','private, no-store')->header('Referrer-Policy','no-referrer');}
 public function pdf(Request $r){$report=PortalVisualReport::capture($r);return PortalPdf::response('pdf.visual-report',compact('report'),'GTA-Portal-Report-'.today()->toDateString());}
}
