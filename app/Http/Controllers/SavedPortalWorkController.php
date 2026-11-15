<?php
namespace App\Http\Controllers;
use App\Support\{PortalActions,SavedPortalWork};
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
class SavedPortalWorkController extends Controller
{
    public function index(Request $r)
    {
        $u=PortalActions::actor($r->user());abort_unless(SavedPortalWork::allowed($u),403);
        $data=$r->validate(['kind'=>['nullable','string',Rule::in(array_keys(SavedPortalWork::KINDS))],'search'=>['nullable','string','max:100'],'page'=>['nullable','integer','min:1','max:1000000']]);
        $kind=$data['kind']??'all';$search=trim($data['search']??'');$source=SavedPortalWork::read($u,$kind,$search);$page=(int)($data['page']??1);
        $items=new LengthAwarePaginator($source['items']->slice(($page-1)*20,20)->values(),$source['items']->count(),20,$page,['path'=>$r->url(),'query'=>$r->except('page')]);
        return response()->view('saved-work.index',compact('items','source','search','kind'))->header('Cache-Control','private, no-store')->header('Referrer-Policy','no-referrer');
    }
}
