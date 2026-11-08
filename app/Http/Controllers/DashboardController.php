<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Apprentice;
use App\Models\ApprenticeTask;
use App\Models\CaseStudy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = Auth::user();

        if ($user->role === UserRole::Apprentice) {
            $apprentice = Apprentice::with([
                'standard',
                'trainer',
                'employer',
                'functionalSkills',
                'mandatoryQualifications',
                'actionPlans.items',
            ])->where('user_id', $user->id)
                ->first();

            $completedTasksCount = $apprentice
                ? ApprenticeTask::where('apprentice_id', $apprentice->apprentice_id)->where('status', 'Completed')->count()
                : 0;

            $submittedTasksCount = $apprentice
                ? ApprenticeTask::where('apprentice_id', $apprentice->apprentice_id)->where('status', 'Submitted')->count()
                : 0;

            return view('dashboard.apprentice', compact('apprentice', 'completedTasksCount', 'submittedTasksCount'));
        }

        // Staff & Employer Dashboard
        $totalApprentices = Apprentice::where('status', 'Live')->count();
        $pendingMarking = ApprenticeTask::pendingMarking()->count();
        $overdueMarking = ApprenticeTask::overdueMarking()->count();
        $approvedCaseStudies = CaseStudy::where('status', 'Approved')->count();

        // Recent submitted tasks for review
        $recentSubmissions = ApprenticeTask::with(['apprentice.user'])
            ->pendingMarking()
            ->orderBy('submitted_at', 'asc')
            ->limit(5)
            ->get();

        return view('dashboard.staff', compact(
            'totalApprentices',
            'pendingMarking',
            'overdueMarking',
            'approvedCaseStudies',
            'recentSubmissions'
        ));
    }
}

