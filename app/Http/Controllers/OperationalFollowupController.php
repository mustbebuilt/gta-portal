<?php
namespace App\Http\Controllers;
use App\Models\{User,Apprentice,OperationalFollowup};
use App\Support\{OperationalFollowups as F,StaffAttention};
use Illuminate\Http\Request;
class OperationalFollowupController extends Controller {
 public function index(Request $r)
 {
     abort_unless(StaffAttention::allowed($r->user()),403);
     $data = $r->validate(['q'=>'nullable|string|max:100','state'=>'nullable|in:all,In progress,Waiting,Resolved','page'=>'nullable|integer|min:1','followup'=>'nullable|uuid']);
     $q = $data['q'] ?? '';
     $state = $data['state'] ?? 'all';
     $records = F::visible($r->user(), $q, $state, $data['followup'] ?? null);
     abort_if(isset($data['followup']) && $records->isEmpty(), 404);
     abort_if($records->count() > 1000, 422, 'Review or narrow the follow-up scope.');
     $page = min((int) ($data['page'] ?? 1), max(1, (int) ceil($records->count()/20)));
     $items = new \Illuminate\Pagination\LengthAwarePaginator(
         $records->forPage($page, 20)->values(), $records->count(), 20, $page,
         ['path' => $r->url(), 'query' => $r->except('page')]
     );
     $owners = User::where('active', true)->orderBy('name')->get()->filter(fn ($user) => StaffAttention::allowed($user));

     return response()->view('operations-desk.index', [
         'records' => $items, 'owners' => $owners, 'source_all' => F::reasons($r->user()), 'q' => $q, 'state' => $state,
     ])->header('Cache-Control','no-store, private')->header('Referrer-Policy','no-referrer');
 }
 public function start(Request $r,Apprentice $apprentice){abort_unless(StaffAttention::allowed($r->user()),403);$d=$r->validate(['source_key'=>'required|regex:/^[a-f0-9]{64}$/D','owner_id'=>'required|integer|exists:users,id','request_token'=>'required|uuid','due'=>'required|date_format:Y-m-d','reference'=>'required|string|max:2000','confirmed'=>'accepted']);F::start($r->user(),(int)$r->session()->get('auth_version'),$apprentice,$d['source_key'],User::findOrFail($d['owner_id']),$d['request_token'],$d['due'],$d['reference']);return back()->with('status','Source-linked follow-up started; the underlying task/review remains in its original workflow.');}
 public function update(Request $r,OperationalFollowup $followup){abort_unless(StaffAttention::allowed($r->user()),403);$d=$r->validate(['version'=>'required|integer|min:1','status'=>'required|in:In progress,Waiting,Resolved','owner_id'=>'required|integer|exists:users,id','due'=>'required|date_format:Y-m-d','reference'=>'required|string|max:2000','confirmed'=>'accepted']);F::update($r->user(),(int)$r->session()->get('auth_version'),$followup,(int)$d['version'],$d['status'],User::findOrFail($d['owner_id']),$d['due'],$d['reference']);return back()->with('status','Follow-up updated. Source flags and actual task/review decisions remain independently checked.');}
}
