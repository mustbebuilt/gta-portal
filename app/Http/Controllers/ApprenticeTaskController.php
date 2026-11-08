<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Apprentice;
use App\Models\ApprenticeTask;
use App\Models\ApprenticeTaskUpload;
use App\Models\Block;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ApprenticeTaskController extends Controller
{
    /**
     * Browse the 10 training blocks and 50 tasks for the learner.
     */
    public function index(Request $request, ?Apprentice $apprentice = null): View
    {
        $user = Auth::user();

        // If no apprentice is passed in route, load the authenticated apprentice
        if (!$apprentice || !$apprentice->exists) {
            if ($user->role === UserRole::Apprentice) {
                $apprentice = Apprentice::where('user_id', $user->id)->firstOrFail();
            } else {
                // For staff without selected learner, default to the first active learner
                $apprentice = Apprentice::where('status', 'Live')->firstOrFail();
            }
        }

        Gate::authorize('view', $apprentice);

        // Fetch all blocks and group tasks by block number
        $blocks = Block::where('standard_id', $apprentice->standard_id)
            ->orWhereNull('standard_id')
            ->orderBy('block_num')
            ->get();

        $tasks = ApprenticeTask::where('apprentice_id', $apprentice->apprentice_id)
            ->with(['uploads'])
            ->orderBy('ablk_id')
            ->orderBy('task_num')
            ->get()
            ->groupBy('ablk_id');

        return view('tasks.index', compact('apprentice', 'blocks', 'tasks'));
    }

    /**
     * View a single task workspace, instructions, evidence uploads, and feedback.
     */
    public function show(ApprenticeTask $task): View
    {
        Gate::authorize('view', $task);

        $task->load(['apprentice.user', 'apprentice.employer', 'trainer', 'uploads']);
        $block = Block::where('block_num', $task->ablk_id)->first();

        return view('tasks.show', compact('task', 'block'));
    }

    /**
     * Upload an evidence file (Job card, practical assessment, photos, reflection doc).
     */
    public function uploadEvidence(Request $request, ApprenticeTask $task): RedirectResponse
    {
        Gate::authorize('submit', $task);

        $request->validate([
            'evidence_file' => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png,docx,doc',
                'max:15360', // 15MB limit
            ],
        ]);

        $file = $request->file('evidence_file');
        $storedPath = $file->store("task_evidence/{$task->at_id}", 'local');

        ApprenticeTaskUpload::create([
            'at_id' => $task->at_id,
            'apprentice_id' => $task->apprentice_id,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'mime_type' => $file->getClientMimeType(),
            'file_size_bytes' => $file->getSize(),
            'status' => 'Pending',
        ]);

        // Move task to In Progress if currently Not Started
        if ($task->status === 'Not Started') {
            $task->update(['status' => 'In Progress']);
        }

        return redirect()->back()->with('success', 'Evidence file uploaded successfully.');
    }

    /**
     * Submit the task for GTA Tutor whole-task marking.
     */
    public function submitForMarking(Request $request, ApprenticeTask $task): RedirectResponse
    {
        Gate::authorize('submit', $task);

        // Verify evidence is present before allowing submission
        if ($task->uploads()->whereNull('superseded_at')->count() === 0) {
            return redirect()->back()->with('error', 'Please upload at least one evidence file before submitting.');
        }

        $task->update([
            'status' => 'Submitted',
            'submitted_at' => now(),
            'progress' => 50.00,
        ]);

        return redirect()->back()->with('success', 'Task submitted to your GTA tutor for marking.');
    }

    /**
     * Securely download an evidence file.
     */
    public function downloadEvidence(ApprenticeTask $task, ApprenticeTaskUpload $upload): StreamedResponse
    {
        Gate::authorize('view', $task);

        if ($upload->at_id !== $task->at_id) {
            abort(404);
        }

        if (!Storage::disk('local')->exists($upload->stored_path)) {
            abort(404, 'Requested evidence file was not found on private storage.');
        }

        return Storage::disk('local')->download($upload->stored_path, $upload->original_filename);
    }
}

