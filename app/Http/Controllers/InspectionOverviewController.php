<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\ApprenticeTask;
use App\Models\CaseStudy;
use App\Models\Otj;
use App\Models\ProgressReview;
use App\Services\AttendanceRagService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InspectionOverviewController extends Controller
{
    public function __construct(
        protected AttendanceRagService $attendanceService
    ) {}

    /**
     * Display the Ofsted & Quality Inspection Overview Dashboard.
     * Preserved Rule from RULES_AND_PERMISSIONS.txt:
     * - Administrator / CEO only.
     * - Read-only aggregation of verified canonical sources.
     * - Explicit provider-wide gaps; no speculative grades or compliance scores.
     */
    public function index(Request $request): View
    {
        $data = $this->aggregateInspectionData();

        return view('inspection.overview', $data);
    }

    /**
     * Export / Print high-fidelity Management & Inspection Evidence Dossier.
     */
    public function printDossier(): View
    {
        $data = $this->aggregateInspectionData();

        return view('inspection.print', $data);
    }

    protected function aggregateInspectionData(): array
    {
        $apprentices = Apprentice::with(['user', 'employer', 'trainer', 'tasks', 'standard'])
            ->where('status', 'Live')
            ->get();

        $totalLearners = $apprentices->count();

        // 1. Attendance RAG Distribution
        $attendanceGreen = 0;
        $attendanceAmber = 0;
        $attendanceRed = 0;
        $attendanceUnknown = 0;

        foreach ($apprentices as $app) {
            $rag = $this->attendanceService->computeRag($app);
            match ($rag['rating']) {
                AttendanceRagService::RAG_GREEN => $attendanceGreen++,
                AttendanceRagService::RAG_AMBER => $attendanceAmber++,
                AttendanceRagService::RAG_RED => $attendanceRed++,
                default => $attendanceUnknown++,
            };
        }

        // 2. Reviews Compliance (Tripartite Signing Rate)
        $totalReviews = ProgressReview::count();
        $signedReviews = ProgressReview::where('status', 'Signed')->count();
        $reviewSigningRate = $totalReviews > 0 ? round(($signedReviews / $totalReviews) * 100, 1) : 0.0;

        // 3. Curriculum & Task Milestones
        $totalTasksCompleted = ApprenticeTask::where('status', 'Completed')->count();
        $totalTasksPossible = $totalLearners * 50;
        $curriculumCompletionRate = $totalTasksPossible > 0 ? round(($totalTasksCompleted / $totalTasksPossible) * 100, 1) : 0.0;
        $overdueMarkingSla = ApprenticeTask::overdueMarking()->count();

        // 4. OTJ Verified Delivery
        $totalVerifiedChunks = Otj::where('otj_trainer_sign_off', true)->sum('time_chunks');
        $totalVerifiedOtjHours = round($totalVerifiedChunks * 0.25, 1);
        $averageOtjPerLearner = $totalLearners > 0 ? round($totalVerifiedOtjHours / $totalLearners, 1) : 0.0;

        // 5. Approved Anonymous Case Studies
        $approvedCaseStudies = CaseStudy::with(['author', 'approver'])
            ->where('status', 'Approved')
            ->orderBy('category')
            ->get();

        return compact(
            'apprentices',
            'totalLearners',
            'attendanceGreen',
            'attendanceAmber',
            'attendanceRed',
            'attendanceUnknown',
            'totalReviews',
            'signedReviews',
            'reviewSigningRate',
            'totalTasksCompleted',
            'totalTasksPossible',
            'curriculumCompletionRate',
            'overdueMarkingSla',
            'totalVerifiedOtjHours',
            'averageOtjPerLearner',
            'approvedCaseStudies'
        );
    }
}

