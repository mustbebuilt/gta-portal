<?php
namespace App\Http\Controllers;
use App\Models\Apprentice;
use App\Support\{PortalActions,ReviewWorkspace as W,PortalPdf};
use Illuminate\Http\Request;
class ReviewWorkspaceController extends Controller
{
 public function index(Request $r)
 {
     $user = PortalActions::actor($r->user());
     abort_unless(\App\Support\ReviewReminders::allowed($user), 403);
     $filters = $r->validate(['search'=>'nullable|string|max:100','group'=>'nullable|integer|min:1','page'=>'nullable|integer|min:1|max:1000000']);

     return to_route('reviews.index', ['view' => 'preparation'] + $filters);
 }
 private function capture(Request $r,Apprentice $a){$u=PortalActions::actor($r->user());abort_unless(\App\Support\SignedReviews::allowed($u,$a),403);$d=$r->validate(['from'=>'nullable|date_format:Y-m-d','to'=>'nullable|date_format:Y-m-d']);$from=$d['from']??\App\Support\ReviewAutomation::defaults($a)['trainingFrom']??today()->subDays(70)->toDateString();$to=$d['to']??today()->toDateString();return W::worksheet($u,$a,$from,$to);}
 public function show(Request $r,Apprentice $apprentice){$worksheet=$this->capture($r,$apprentice);return response()->view('review-workspace.show',compact('worksheet'))->header('Cache-Control','private, no-store')->header('Referrer-Policy','no-referrer');}
 public function pdf(Request $r,Apprentice $apprentice){$worksheet=$this->capture($r,$apprentice);return PortalPdf::response('pdf.review-worksheet',compact('worksheet'),'GTA-Review-Preparation-'.$apprentice->reference);}
}
