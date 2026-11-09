<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\ApprenticeTask;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\CaseStudy;
use App\Models\Otj;
use App\Models\ProgressReview;
use App\Models\TutorGroup;
use App\Services\AttendanceRagService;
use App\Services\OtjCalculationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ReportingController extends Controller
{
    public function __construct(
        protected AttendanceRagService $attendanceService,
        protected OtjCalculationService $otjService
    ) {}

    /**
     * Display the General Reports Dashboard & Directory.
     * Preserved Rule: Accessible to Staff & Admins.
     */
    public function index(Request $request): View
    {
        $user = Auth::user();

        if (!$user->isStaff()) {
            abort(403, 'Access Restricted: Reporting home is accessible to training and administrative staff only.');
        }

        // Selected group filter
        $groupId = $request->input('group_id');
        $query = Apprentice::with(['user', 'employer', 'trainer', 'tasks', 'standard'])
            ->where('status', 'Live');

        if ($groupId) {
            $query->whereHas('tutorGroups', function ($q) use ($groupId) {
                $q->where('tutor_groups.tutor_group_id', $groupId);
            });
        }

        $apprentices = $query->get();
        $totalLearners = $apprentices->count();

        // 1. Attendance Metrics
        $greenAttendance = 0;
        $amberAttendance = 0;
        $redAttendance = 0;
        foreach ($apprentices as $app) {
            $rag = $this->attendanceService->computeRag($app);
            if ($rag['rating'] === 'Green') $greenAttendance++;
            elseif ($rag['rating'] === 'Amber') $amberAttendance++;
            elseif ($rag['rating'] === 'Red') $redAttendance++;
        }

        // 2. OTJ Compliance Metrics
        $onTrackOtj = 0;
        $behindOtj = 0;
        foreach ($apprentices as $app) {
            $metrics = $this->otjService->computeMetrics($app);
            if (($metrics['rag_rating'] ?? '') === \App\Services\OtjCalculationService::RAG_GREEN) $onTrackOtj++;
            else $behindOtj++;
        }

        // 3. Marking SLA Metrics
        $pendingMarking = ApprenticeTask::pendingMarking()->count();
        $overdueMarking = ApprenticeTask::overdueMarking()->count();

        // 4. Reviews & Case Studies
        $totalReviews = ProgressReview::count();
        $approvedCaseStudies = CaseStudy::where('status', 'Approved')->count();

        $tutorGroups = TutorGroup::all();

        // Searchable Report Directory Definitions
        $reportsDirectory = [
            [
                'id' => 'attendance-audit',
                'title' => 'Cohort Attendance & Session Register Audit',
                'category' => 'Attendance',
                'description' => 'Complete statutory register logs, attendance percentages, and RAG risk flags (Green >=95%, Amber 90-94.9%, Red <90%).',
                'route' => route('attendance.index'),
                'icon' => '📅',
            ],
            [
                'id' => 'marking-sla',
                'title' => 'Tutor 7-Day Marking SLA & Backlog Report',
                'category' => 'Quality',
                'description' => 'Queue of apprentice tasks submitted for marking with waiting calendar days and SLA threshold tracking.',
                'route' => route('marking.index'),
                'icon' => '✍️',
            ],
            [
                'id' => 'otj-ledger',
                'title' => 'Off-The-Job (OTJ) Training Compliance Ledger',
                'category' => 'Funding',
                'description' => '15-minute chunk verified training logs against agreed expected-to-date targets (663-hour baseline).',
                'route' => route('otj.index'),
                'icon' => '⏱️',
            ],
            [
                'id' => 'reviews-cadence',
                'title' => 'Tripartite Progress Review Cadence Health',
                'category' => 'Reviews',
                'description' => '10-week initial and 70-day post-meeting cadence tracker with SHA-256 tripartite signature verification.',
                'route' => route('reviews.index'),
                'icon' => '🤝',
            ],
            [
                'id' => 'inspection-dossier',
                'title' => 'Ofsted Inspection & Governance Evidence Dossier',
                'category' => 'Inspection',
                'description' => 'High-fidelity printable evidence aggregator across curriculum, registers, OTJ, and reviews.',
                'route' => route('inspection.overview'),
                'icon' => '🔍',
            ],
            [
                'id' => 'case-studies-bank',
                'title' => 'Approved Anonymous Case Studies Repository',
                'category' => 'Quality',
                'description' => 'Dual-admin approved practice case studies across 11 key vocational and learner support categories.',
                'route' => route('case-studies.index'),
                'icon' => '📖',
            ],
        ];

        return view('reports.index', compact(
            'totalLearners',
            'greenAttendance',
            'amberAttendance',
            'redAttendance',
            'onTrackOtj',
            'behindOtj',
            'pendingMarking',
            'overdueMarking',
            'totalReviews',
            'approvedCaseStudies',
            'tutorGroups',
            'reportsDirectory',
            'groupId'
        ));
    }
}

