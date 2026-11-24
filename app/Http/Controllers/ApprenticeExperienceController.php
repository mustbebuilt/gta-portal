<?php
namespace App\Http\Controllers;
use App\Models\ApprenticeExperienceEntry;
use App\Support\ApprenticeExperience;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
class ApprenticeExperienceController extends Controller {
 public function home(Request $r) {
  $a=ApprenticeExperience::profile($r->user());$position=$a?ApprenticeExperience::home($a):null;$review=$a?\App\Support\ReviewNextStep::for($r->user(),$a):null;if($position){$position['review']=$review['cycle'];}return response()->view('experience.home',['apprentice'=>$a,'position'=>$position,'reviewSchedule'=>$review,'weeklyReminderCount'=>$a?\App\Support\WeeklyRetention::currentReminderCount($r->user(),[$a->id]):0,'gatewaySummary'=>$a?\App\Support\GatewaySummary::for($r->user(),$a):null],$a?200:409)->header('Cache-Control','no-store, private');
 }
 public function index(Request $r) {
  $u=$r->user();$learner=$u->role==='apprentice';$a=$learner?ApprenticeExperience::profile($u):null;
  if($learner){abort_unless($a,409);$q=ApprenticeExperienceEntry::where('apprentice_id',$a->id)->where('requester_id',$u->id);}
  else {$q=ApprenticeExperience::queue($u);}
  $filter=$r->validate(['filter'=>['nullable',Rule::in(['open','all','achievement','help'])]])['filter']??'open';
  if($filter==='open'){$q->whereIn('state',['New','Received','Referred']);}elseif($filter==='achievement'){$q->where('kind','achievement');}elseif($filter==='help'){$q->where('kind','!=','achievement');}
  return response()->view('experience.entries',['apprentice'=>$a,'learner'=>$learner,'filter'=>$filter,'entries'=>$q->with('apprentice:id,reference,first_name,last_name')->orderByDesc('created_at')->orderByDesc('id')->paginate(15)->withQueryString()],200)->header('Cache-Control','no-store, private');
 }
 public function create(Request $r) {
  abort_unless($r->user()->role==='apprentice',403);
  $data=$r->validate(['request_token'=>['required','uuid'],'kind'=>['required',Rule::in(array_keys(ApprenticeExperience::KINDS))],'title'=>['required','string','max:160'],'detail'=>['required','string','max:5000'],'happened_on'=>['required_if:kind,achievement','nullable','date_format:Y-m-d','before_or_equal:today']]);
  if($data['kind']!=='achievement'){$data['happened_on']=null;}
  $e=ApprenticeExperience::create($r->user(),(int)$r->session()->get('auth_version',$r->user()->auth_version),$data);
  return to_route('experience.index',['filter'=>'all'])->with('status','Saved. Your reference is GTA-'.$e->id.'. This is a portal receipt; a staff response will appear here.');
 }
 public function respond(Request $r,ApprenticeExperienceEntry $entry) {
  abort_unless(ApprenticeExperience::canHandle($r->user(),$entry),403);
  $data=$r->validate(['version'=>['required','integer','min:1'],'state'=>['required',Rule::in(['Received','Closed','Approved','Referred'])],'response'=>['required','string','max:5000']]);
  ApprenticeExperience::respond($r->user(),(int)$r->session()->get('auth_version',$r->user()->auth_version),$entry,$data);
  return to_route('experience.index',['filter'=>'all'])->with('status','Response saved. The apprentice can read it in their portal.');
 }
}
