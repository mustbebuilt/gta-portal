<?php

namespace App\Http\Controllers;

use App\Models\ApprenticeTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TaskMarkingController extends Controller
{
    public function index(): View
    {
        $tasks = ApprenticeTask::with(['apprentice.user'])
            ->pendingMarking()
            ->orderBy('submitted_at', 'asc')
            ->get();

        return view('marking.index', compact('tasks'));
    }

    public function approve(Request $request, ApprenticeTask $task): RedirectResponse
    {
        $request->validate([
            'feedback' => ['nullable', 'string'],
            'knowledge_score' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $task->update([
            'status' => 'Completed',
            'progress' => 100.00,
            'completion_date' => now()->toDateString(),
            'feedback_trainer_id' => $request->user()->id,
            'feedback_date' => now(),
            'feedback' => $request->input('feedback', 'Tutor whole-task approval recorded.'),
            'knowledge_score' => $request->input('knowledge_score'),
        ]);

        return redirect()->back()->with('success', "Task #{$task->task_num} marked as Approved.");
    }

    public function refer(Request $request, ApprenticeTask $task): RedirectResponse
    {
        $request->validate([
            'feedback' => ['required', 'string', 'min:5'],
        ]);

        $task->update([
            'status' => 'Referred',
            'feedback_trainer_id' => $request->user()->id,
            'feedback_date' => now(),
            'feedback' => $request->input('feedback'),
        ]);

        return redirect()->back()->with('info', "Task returned to apprentice for revisions.");
    }
}

