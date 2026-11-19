<?php
namespace App\Http\Controllers;
use App\Models\{Apprentice,Employer};
use App\Support\EmployerWorkflow68;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
class EmployerSetup68Controller extends Controller {
 public function show(Request $r){abort_unless($r->user()->can('create',Apprentice::class),403);$q=trim((string)$r->query('search',''));abort_if(mb_strlen($q)>100,422);$matches=$q===''?collect():Employer::where(fn($b)=>$b->whereRaw('LOWER(name) LIKE ? ESCAPE \'!\'',['%'.str_replace(['!','%','_'],['!!','!%','!_'],mb_strtolower($q)).'%'])->orWhereRaw('UPPER(reference)=?',[strtoupper($q)]))->limit(20)->get();return response()->view('setup68.employer',['token'=>old('token',(string)Str::uuid()),'matches'=>$matches])->header('Cache-Control','private, no-store');}
 public function store(Request $r){$v=$r->validate(['token'=>['required','uuid'],'data'=>['required','array']]);$e=EmployerWorkflow68::create($r->user(),(int)$r->session()->get('auth_version'),$v['token'],$v['data']);return to_route('directories.show',['employers',$e])->with('status','Employer created. No invitation or message was sent.');}
}
