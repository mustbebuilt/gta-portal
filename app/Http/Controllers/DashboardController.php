<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\CaseStudy;
use App\Models\LearningTask;
use App\Models\ProgrammeOutcome;
use App\Models\Qualification;
use App\Models\SmartAction;
use App\Models\SupportConcern;
use App\Models\SupportPlan;
use App\Models\TrainingTopic;
use App\Models\TutorGroup;
use App\Support\AttendanceLedger;
use App\Support\JourneyEvents;
use App\Support\LearningAccess as Access;
use App\Support\PortalPdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $r): View
    {
        $data=$this->data($r,false);
        $filter=$r->query('priority','all');$page=$r->query('priority_page','1');
        abort_unless(is_string($filter) && in_array($filter,['all','overdue','today','checks'],true),422,'Choose a recognised work filter.');
        abort_unless((is_string($page) || is_int($page)) && ctype_digit((string)$page) && (int)$page>=1 && (int)$page<=1000000,422,'Choose a valid work-list page.');
        $priorities=\App\Support\DashboardPriorities::for($r->user(),$data['apprentices'],$data['tasks'],$data['actions']);
        $filtered=$filter==='all'?$priorities['rows']:$priorities['rows']->where('bucket',$filter)->values();
        $page=min((int)$page,max(1,(int)ceil($filtered->count()/15)));
        $list=new \Illuminate\Pagination\LengthAwarePaginator($filtered->forPage($page,15)->values(),$filtered->count(),15,$page,['path'=>$r->url(),'query'=>$r->except('priority_page'),'pageName'=>'priority_page']);
        $reviewSummary=$r->user()->role==='apprentice'?null:\App\Support\ReviewFollowup::for($r->user(),$data['apprentices']);
        $registerSummary=null;
        if($r->user()->role!=='apprentice'){
            $registerAccess=\App\Support\RegisterFollowup::visible($r->user());
            if(!$r->filled('group') || $registerAccess['groups']->contains('id',(int)$r->query('group'))){
                $visibleRegisters=$registerAccess['sessions'];
                if($r->filled('group')){$visibleRegisters=$visibleRegisters->where('tutor_group_id',(int)$r->query('group'));}
                if($r->filled('apprentice')){$visibleRegisters=$visibleRegisters->filter(fn($s)=>in_array((int)$r->query('apprentice'),\App\Support\RegisterFollowup::roster($s)['ids'],true));}
                $registerSummary=\App\Support\RegisterFollowup::for($visibleRegisters);
            }
        }
        return view('dashboard.index',$data+['priorities'=>$priorities,'priorityFilter'=>$filter,'priorityList'=>$list,'reviewSummary'=>$reviewSummary,'registerSummary'=>$registerSummary]);
    }

    public function report(Request $r): View
    {
        return view('dashboard.report', $this->data($r));
    }

    public function pdf(Request $r): Response
    {
        return PortalPdf::response('pdf.operational', $this->data($r), 'GTA-Operational-Report-'.today()->format('Y-m-d'));
    }

    public function inspection(Request $r): View
    {
        abort_unless(Access::manager($r->user()), 403);

        return view('dashboard.inspection', ['apprentices' => Apprentice::count(), 'concerns' => SupportConcern::where('status', '!=', 'Closed')->count(), 'plans' => SupportPlan::where('status', 'Active')->count(), 'tasks' => LearningTask::count(), 'topics' => TrainingTopic::count(), 'qualifications' => Qualification::count(), 'outcomeRecords' => ProgrammeOutcome::count(), 'achieved' => Qualification::whereIn('status', ['Achieved', 'Already held'])->count(), 'cases' => CaseStudy::where('status', 'Approved')->get()->filter(fn ($c) => $c->currentApprovedCopy())]);
    }

    public function journey(Request $r, Apprentice $apprentice): View
    {
        abort_unless(Access::training($r->user(), $apprentice), 403);

        return view('dashboard.journey', ['apprentice' => $apprentice, 'events' => JourneyEvents::for($apprentice)]);
    }

    private function data(Request $r,bool $includeAttendance=true): array
    {
        abort_unless(in_array($r->user()->role, ['admin', 'ceo', 'tutor', 'apprentice'], true), 403);
        $visible = Apprentice::visibleTo($r->user())->with(['groupRecord', 'tutor'])->orderBy('last_name')->get();
        $groups = TutorGroup::whereIn('id', $visible->pluck('tutor_group_id')->filter())->orderBy('name')->get();
        $scope = $visible;
        if ($r->filled('group')) {
            $scope = $scope->where('tutor_group_id', (int) $r->query('group'));
        }
        if ($r->filled('apprentice')) {
            $a = $scope->firstWhere('id', (int) $r->query('apprentice'));
            abort_unless($a, 404);
            $scope = collect([$a]);
        }
        $ids = $scope->pluck('id');
        $tasks = LearningTask::with('apprentice')->whereIn('apprentice_id', $ids)->get();
        $topics = TrainingTopic::whereIn('apprentice_id', $ids)->get();
        $qualifications = Qualification::whereIn('apprentice_id', $ids)->get();
        $actions = SmartAction::with(['task', 'apprentice'])->whereIn('apprentice_id', $ids)->get();
        $metrics = ['apprentices' => $scope->count(), 'approved' => $tasks->where('status', 'Approved')->count(), 'tasks' => $tasks->count(), 'awaiting' => $tasks->where('status', 'Awaiting marking')->count(), 'overdue_marking' => $tasks->filter(fn ($t) => $t->markingOverdue())->count(), 'marking_date_checks' => $tasks->filter(fn ($t) => $t->status === 'Awaiting marking' && ! $t->submitted_at)->count(), 'overdue_actions' => $actions->filter(fn ($a) => $a->overdue())->count(), 'qualifications_achieved' => $qualifications->filter(fn ($q) => $q->achieved())->count(), 'qualifications_overdue' => $qualifications->filter(fn ($q) => $q->position() === 'Overdue')->count(), 'qualification_date_checks' => $qualifications->filter(fn ($q) => $q->position() === 'Date check')->count()];

        $tutorMarking = [];
        if ($r->user()->role !== 'apprentice') {
            foreach ($scope->groupBy(fn ($a) => $a->tutor_id ?? 'unassigned') as $key => $people) {
                $waiting = $tasks->whereIn('apprentice_id', $people->pluck('id'))->where('status', 'Awaiting marking');
                $overdue = $waiting->filter(fn ($t) => $t->markingOverdue());
                $checks = $waiting->filter(fn ($t) => ! $t->submitted_at || $t->submitted_at->gt(now()))->count();
                $tutorMarking[] = ['name' => $people->first()->tutor?->name ?? 'Main tutor not assigned', 'caseload' => $people->count(), 'waiting' => $waiting->count(), 'overdue' => $overdue->count(), 'checks' => $checks, 'oldest' => $waiting->whereNotNull('submitted_at')->min('submitted_at')?->format('d M Y H:i'), 'waiting_percent' => $waiting->count() && ! $checks ? round(100 * $overdue->count() / $waiting->count(), 1) : null, 'affected_apprentices' => $overdue->pluck('apprentice_id')->unique()->count()];
            }
        }

        return ['tutorMarking' => $tutorMarking, 'attendanceLedger' => $includeAttendance?AttendanceLedger::forIds($ids->all()):null, 'outcomeContexts' => Access::manager($r->user()) ? ProgrammeOutcome::whereIn('apprentice_id', $ids)->get()->keyBy('apprentice_id') : collect(), 'apprentices' => $scope, 'visible' => $visible, 'groups' => $groups, 'metrics' => $metrics, 'tasks' => $tasks, 'topics' => $topics, 'qualifications' => $qualifications, 'actions' => $actions, 'manager' => Access::manager($r->user())];
    }
}
