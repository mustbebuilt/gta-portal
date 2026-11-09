<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\ActionPlanItem;
use App\Models\Apprentice;
use App\Models\ApprenticeTask;
use App\Models\ProgressReview;
use App\Models\SupportPlan;
use App\Services\AttendanceRagService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AttentionController extends Controller
{
    public function __construct(
        protected AttendanceRagService $attendanceService
    ) {}

    /**
     * Display the 'Needs Attention' compliance dashboard matching needs-attention.html.
     */
    public function index(Request $request): View
    {
        $user = Auth::user();

        if (!$user->isStaff()) {
            abort(403, 'Needs attention dashboard is accessible to staff and administrators only.');
        }

        // 1. Overdue marking items (7-day SLA breached)
        $overdueMarking = ApprenticeTask::overdueMarking()
            ->with(['apprentice.user'])
            ->orderBy('submitted_at', 'asc')
            ->get();

        // 2. Attendance risks (Red RAG <90% or Amber 90-94.9%)
        $attendanceRisks = [];
        $activeApprentices = Apprentice::with(['user', 'employer'])->where('status', 'Live')->get();
        foreach ($activeApprentices as $app) {
            $rag = $this->attendanceService->computeRag($app);
            if ($rag['rating'] === 'Red' || $rag['rating'] === 'Amber') {
                $attendanceRisks[] = [
                    'apprentice' => $app,
                    'rag' => $rag,
                ];
            }
        }

        // 3. Overdue SMART actions
        $overdueActions = ActionPlanItem::where('status', 'To do')
            ->where('target_date', '<', Carbon::today())
            ->with(['actionPlan.apprentice.user'])
            ->orderBy('target_date')
            ->get();

        // 4. Overdue reviews
        $overdueReviews = ProgressReview::where('status', '!=', 'Completed')
            ->where('due_date', '<', Carbon::today())
            ->with(['apprentice.user', 'trainer'])
            ->orderBy('due_date')
            ->get();

        // 5. Open support/ALS concerns
        $openSupportPlans = SupportPlan::where('status', 'active')
            ->with(['apprentice.user', 'planLead'])
            ->get();

        $totalIssuesCount = $overdueMarking->count() + count($attendanceRisks) + $overdueActions->count() + $overdueReviews->count() + $openSupportPlans->count();

        return view('attention.index', compact(
            'overdueMarking',
            'attendanceRisks',
            'overdueActions',
            'overdueReviews',
            'openSupportPlans',
            'totalIssuesCount'
        ));
    }
}

