<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Apprentice;
use App\Models\ApprenticeTask;
use App\Models\Block;
use App\Models\ProgressReview;
use App\Services\AttendanceRagService;
use App\Services\OtjCalculationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ApprenticeJourneyController extends Controller
{
    public function __construct(
        protected AttendanceRagService $attendanceService,
        protected OtjCalculationService $otjService
    ) {}

    /**
     * Display the chronological Apprentice Journey visual roadmap.
     */
    public function show(Request $request, ?Apprentice $apprentice = null): View
    {
        $user = Auth::user();

        if (!$apprentice || !$apprentice->exists) {
            if ($user->role === UserRole::Apprentice) {
                $apprentice = Apprentice::where('user_id', $user->id)->firstOrFail();
            } else {
                $apprentice = Apprentice::where('status', 'Live')->firstOrFail();
            }
        }

        Gate::authorize('view', $apprentice);

        $apprentice->load(['user', 'employer', 'trainer', 'standard']);

        $blocks = Block::orderBy('block_num')->get();
        $tasks = ApprenticeTask::where('apprentice_id', $apprentice->apprentice_id)->get();
        $reviews = ProgressReview::where('apprentice_id', $apprentice->apprentice_id)->orderBy('review_sequence')->get();

        $otjMetrics = $this->otjService->computeMetrics($apprentice);
        $attendanceRag = $this->attendanceService->computeRag($apprentice);

        // Calculate block-by-block progress
        $blockProgress = [];
        foreach ($blocks as $block) {
            $bTasks = $tasks->where('ablk_id', $block->block_num);
            $done = $bTasks->where('status', 'Completed')->count();
            $blockProgress[$block->block_num] = [
                'block' => $block,
                'total' => $bTasks->count() ?: 5,
                'completed' => $done,
                'status' => $done === 5 ? 'Completed' : ($done > 0 ? 'In Progress' : 'Upcoming'),
            ];
        }

        return view('journey.show', compact(
            'apprentice',
            'blocks',
            'blockProgress',
            'reviews',
            'otjMetrics',
            'attendanceRag'
        ));
    }
}

