<?php
namespace App\Http\Controllers;
use App\Models\Apprentice;
use App\Support\{PortalActions,LearningAccess,CommunicationReadiness as C,ApprenticeExperience};
use Illuminate\Http\Request;
class CommunicationReadinessController extends Controller
{
 public function index(Request $r){$u=PortalActions::actor($r->user());abort_unless(LearningAccess::manager($u),403);$d=$r->validate(['search'=>'nullable|string|max:100','page'=>'nullable|integer|min:1|max:1000000']);$search=trim($d['search']??'');$q=Apprentice::query();if($search!==''){$p=PortalActionsController::pattern($search);$q->where(fn($q)=>$q->whereRaw('LOWER(reference) LIKE ? ESCAPE \'!\'',[$p])->orWhereRaw('LOWER(first_name) LIKE ? ESCAPE \'!\'',[$p])->orWhereRaw('LOWER(last_name) LIKE ? ESCAPE \'!\'',[$p]));}$people=$q->orderBy('last_name')->orderBy('first_name')->orderBy('id')->paginate(20)->withQueryString();$rows=[];foreach($people as$a){$rows[$a->id]=C::person($a);}$configuration=C::configuration();return response()->view('communications.index',compact('people','rows','configuration','search'))->header('Cache-Control','private, no-store')->header('Referrer-Policy','no-referrer');}
 public function own(Request $r){$u=PortalActions::actor($r->user());$a=ApprenticeExperience::profile($u);abort_unless($a,409);$status=C::person($a);return response()->view('communications.own',compact('status'))->header('Cache-Control','private, no-store')->header('Referrer-Policy','no-referrer');}
}
