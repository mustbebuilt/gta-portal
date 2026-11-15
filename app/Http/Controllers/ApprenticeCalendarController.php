<?php
namespace App\Http\Controllers;
use App\Support\ApprenticeCalendar as C;
use Illuminate\Http\Request;
class ApprenticeCalendarController extends Controller
{
 private function capture(Request $r){$d=$r->validate(['from'=>'nullable|date_format:Y-m-d','to'=>'nullable|date_format:Y-m-d']);return C::capture($r->user(),$d['from']??today()->toDateString(),$d['to']??today()->addDays(90)->toDateString());}
 public function index(Request $r){$calendar=$this->capture($r);return response()->view('calendar.index',compact('calendar'))->header('Cache-Control','private, no-store')->header('Referrer-Policy','no-referrer');}
 public function download(Request $r){$calendar=$this->capture($r);return response(C::ics($calendar),200,['Content-Type'=>'text/calendar; charset=UTF-8','Content-Disposition'=>'attachment; filename="GTA-Portal-Dates.ics"','Cache-Control'=>'private, no-store','Referrer-Policy'=>'no-referrer']);}
}
