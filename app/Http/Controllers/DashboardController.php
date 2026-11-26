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
use App\Support\Workday83;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $r): View
    {
        abort_unless(in_array($r->user()->role,['admin','ceo','tutor','apprentice'],true),403);
        $r->validate(['group'=>['nullable','integer','min:1'],'apprentice'=>['nullable','integer','min:1']]);
        $data=$this->data($r,false);
        $filter = $r->query('priority', 'all');
        $type = $r->query('work_type', 'all');
        $page = $r->query('priority_page', '1');
        $types = Workday83::types($r->user());
        abort_unless(is_string($filter) && isset(Workday83::STATUSES[$filter]), 422, 'Choose a recognised work status.');
        abort_unless(is_string($type) && isset($types[$type]), 422, 'Choose a recognised work type.');
        abort_unless((is_string($page) || is_int($page)) && ctype_digit((string)$page) && (int)$page>=1 && (int)$page<=1000000,422,'Choose a valid work-list page.');
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
        $training=\App\Support\DashboardPriorities::for($r->user(),$data['apprentices'],$data['tasks'],$data['actions']);
        $priorities = Workday83::combine($r->user(), $training['rows'], $reviewSummary, $registerSummary);
        $typed = $type === 'all' ? $priorities['rows'] : $priorities['rows']->where('kind', $type);
        $workCounts = Workday83::counts($typed);
        $filtered = $filter === 'all' ? $typed : $typed->where('bucket', $filter);
        $filtered = $filtered->values();
        $page = min((int) $page, max(1, (int) ceil($filtered->count() / 15)));
        $workScope = Workday83::scope([...$r->query(), 'priority_page'=>$page], $r->user());
        $list = new \Illuminate\Pagination\LengthAwarePaginator($filtered->forPage($page, 15), $filtered->count(), 15, $page,
            ['path'=>$r->url(), 'query'=>\Illuminate\Support\Arr::except($workScope, ['priority_page']), 'pageName'=>'priority_page']);
        $weeklyReminderCount = in_array($r->user()->role, ['tutor', 'apprentice'], true)
            ? \App\Support\WeeklyRetention::currentReminderCount($r->user(), $data['apprentices']->pluck('id')->all())
            : 0;
        return view('dashboard.index',$data+['priorities'=>$priorities,'priorityFilter'=>$filter,'priorityList'=>$list,'reviewSummary'=>$reviewSummary,'registerSummary'=>$registerSummary,'weeklyReminderCount'=>$weeklyReminderCount,
            'workType'=>$type, 'workTypes'=>$types, 'workCounts'=>$workCounts, 'workScope'=>$workScope]);
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

    public function journey(Request $r, Apprentice $apprentice): Response
    {
        abort_unless(Access::training($r->user(), $apprentice), 403);

        return response()->view('dashboard.journey', ['apprentice' => $apprentice]+\App\Support\JourneyOverview::for($r->user(),$apprentice))->header('Cache-Control','no-store, private')->header('Referrer-Policy','no-referrer');
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
        $taskQuery = LearningTask::with('apprentice')->whereIn('apprentice_id', $ids);
        if ($includeAttendance) {
            $tasks = $taskQuery->get();
        } else {
            // The dashboard needs decision metadata, not complete task instructions, answers or evidence history.
            // This projection is used only for this read response; task forms and writes fetch their original records.
            $tasks = $taskQuery->get(['id','apprentice_id','reference','block','title','theme','status','due_date','submitted_at','template->kind as summary_kind','template->catalogueId as summary_catalogue']);
            foreach ($tasks as $task) {
                $attributes = $task->getAttributes();
                $attributes['template'] = json_encode(['kind'=>$task->summary_kind??'source-pending','catalogueId'=>$task->summary_catalogue??'autocare'], JSON_THROW_ON_ERROR);
                unset($attributes['summary_kind'],$attributes['summary_catalogue']);
                $task->setRawAttributes($attributes, true);
            }
        }
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
