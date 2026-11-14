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
        return view('dashboard.index', $this->data($r));
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

    private function data(Request $r): array
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

        return ['tutorMarking' => $tutorMarking, 'attendanceLedger' => AttendanceLedger::forIds($ids->all()), 'outcomeContexts' => Access::manager($r->user()) ? ProgrammeOutcome::whereIn('apprentice_id', $ids)->get()->keyBy('apprentice_id') : collect(), 'apprentices' => $scope, 'visible' => $visible, 'groups' => $groups, 'metrics' => $metrics, 'tasks' => $tasks, 'topics' => $topics, 'qualifications' => $qualifications, 'actions' => $actions, 'manager' => Access::manager($r->user())];
    }
}
