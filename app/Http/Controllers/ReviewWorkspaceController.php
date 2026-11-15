<?php
namespace App\Http\Controllers;
use App\Models\Apprentice;
use App\Support\{PortalActions,ReviewWorkspace as W,PortalPdf};
use Illuminate\Http\Request;
class ReviewWorkspaceController extends Controller
{
 public function index(Request $r){$u=PortalActions::actor($r->user());$d=$r->validate(['search'=>'nullable|string|max:100','group'=>'nullable|integer|min:1','page'=>'nullable|integer|min:1|max:1000000']);$search=trim($d['search']??'');$q=W::people($u)->when($d['group']??null,fn($q,$id)=>$q->where('tutor_group_id',$id));if($search!==''){$p=PortalActionsController::pattern($search);$q->where(fn($q)=>$q->whereRaw('LOWER(reference) LIKE ? ESCAPE \'!\'',[$p])->orWhereRaw('LOWER(first_name) LIKE ? ESCAPE \'!\'',[$p])->orWhereRaw('LOWER(last_name) LIKE ? ESCAPE \'!\'',[$p]));}$people=$q->orderBy('last_name')->orderBy('first_name')->orderBy('id')->paginate(20)->withQueryString();$positions=[];foreach($people as$a){$positions[$a->id]=W::position($u,$a);}return response()->view('review-workspace.index',compact('people','positions','search'))->header('Cache-Control','private, no-store')->header('Referrer-Policy','no-referrer');}
 private function capture(Request $r,Apprentice $a){$u=PortalActions::actor($r->user());abort_unless(\App\Support\SignedReviews::allowed($u,$a),403);$d=$r->validate(['from'=>'nullable|date_format:Y-m-d','to'=>'nullable|date_format:Y-m-d']);$from=$d['from']??\App\Support\ReviewAutomation::defaults($a)['trainingFrom']??today()->subDays(70)->toDateString();$to=$d['to']??today()->toDateString();return W::worksheet($u,$a,$from,$to);}
 public function show(Request $r,Apprentice $apprentice){$worksheet=$this->capture($r,$apprentice);return response()->view('review-workspace.show',compact('worksheet'))->header('Cache-Control','private, no-store')->header('Referrer-Policy','no-referrer');}
 public function pdf(Request $r,Apprentice $apprentice){$worksheet=$this->capture($r,$apprentice);return PortalPdf::response('pdf.review-worksheet',compact('worksheet'),'GTA-Review-Preparation-'.$apprentice->reference);}
}
