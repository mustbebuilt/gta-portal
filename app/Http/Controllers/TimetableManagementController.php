<?php
namespace App\Http\Controllers;

use App\Models\{User,Programme,TrainingSession};
use App\Support\{CentrePlanning,TimetableGovernance as M,TimetableInsights as I};
use Illuminate\Http\Request;

class TimetableManagementController extends Controller
{
    private function allowed(Request $r): void { abort_unless(M::manager($r->user()),403); }
    private function view(string $name,array $data) {
        return response()->view('centre-planning.'.$name,$data)
            ->header('Cache-Control','no-store, private')->header('Referrer-Policy','no-referrer');
    }
    public function index(Request $r) {
        $this->allowed($r);
        $d=$r->validate(['from'=>['nullable','date_format:Y-m-d'],'to'=>['nullable','date_format:Y-m-d'],
            'programme'=>['nullable','integer','exists:programmes,id']]);
        $from=$d['from']??today()->startOfWeek()->toDateString();
        $to=$d['to']??today()->endOfWeek()->toDateString();
        return $this->view('management',['report'=>I::report($r->user(),$from,$to,
            isset($d['programme'])?(int)$d['programme']:null),
            'programmes'=>Programme::orderBy('name')->get(),
            'canConfirm'=>CentrePlanning::allowed($r->user()),
            'teachers'=>User::whereIn('role',['tutor','functional_skills_tutor'])->orderBy('name')->get()]);
    }
    public function confirm(Request $r,TrainingSession $session) {
        $this->allowed($r);
        $d=$r->validate(['version'=>['required','integer','min:0'],'staff_id'=>['required','integer','exists:users,id'],
            'source_hash'=>['required','regex:/^[a-f0-9]{64}$/D'],'cover'=>['required','boolean'],
            'reason'=>['required','string','max:1000'],'confirmed'=>['accepted']]);
        I::confirm($r->user(),(int)$r->session()->get('auth_version'),$session,User::findOrFail($d['staff_id']),
            (int)$d['version'],$d['source_hash'],(bool)$d['cover'],$d['reason']);
        return back()->with('status','Actual teaching attribution confirmed. No register marks or OTJ records changed.');
    }
}
