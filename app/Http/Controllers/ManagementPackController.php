<?php
namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\ManagementPack;
use App\Models\TutorGroup;
use App\Support\LearningAccess;
use App\Support\ManagementSnapshot;
use App\Support\PortalPdf;
use App\Support\ReviewedImports;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ManagementPackController extends Controller
{
    private function allowed(Request $r): void {abort_unless(LearningAccess::manager($r->user()),403);}
    private function people(Request $r)
    {
        $r->validate(['group'=>['nullable','integer','exists:tutor_groups,id']]);
        return Apprentice::with(['tutor','programmeRecord','groupRecord'])->when($r->filled('group'),fn($q)=>$q->where('tutor_group_id',$r->integer('group')))->orderBy('id')->get();
    }
    public function index(Request $r)
    {
        $this->allowed($r);return view('management.index',['snapshot'=>ManagementSnapshot::capture($this->people($r)),'groups'=>TutorGroup::orderBy('name')->get(),'packs'=>ManagementPack::orderByDesc('id')->paginate(15)]);
    }
    public function capture(Request $r)
    {
        $this->allowed($r);$r->validate(['title'=>['required','string','max:180'],'confirmed'=>['accepted']]);
        $pack=DB::transaction(function() use($r){
            $actor=\App\Models\User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();abort_unless(LearningAccess::manager($actor),403);
            $snapshot=ManagementSnapshot::capture($this->people($r));$snapshot['captured_actor']=['id'=>$actor->id,'name'=>$actor->name];
            $row=ManagementPack::create(['captured_by'=>$r->user()->id,'title'=>$r->input('title'),'snapshot'=>$snapshot,'snapshot_hash'=>ReviewedImports::hash($snapshot),'created_at'=>now()]);
            return $row;
        });
        return to_route('management.show',$pack)->with('status','Fixed operational management pack saved. Later source changes do not rewrite it. No external message or compliance approval recorded.');
    }
    public function show(Request $r,ManagementPack $pack)
    {
        $this->allowed($r);abort_unless(ReviewedImports::hash($pack->snapshot)===$pack->snapshot_hash,409);
        return view('management.show',compact('pack'));
    }
    public function pdf(Request $r,ManagementPack $pack)
    {
        $this->allowed($r);abort_unless(ReviewedImports::hash($pack->snapshot)===$pack->snapshot_hash,409);
        return PortalPdf::response('pdf.management-pack',compact('pack'),'GTA-Management-Pack-'.$pack->id);
    }
}
