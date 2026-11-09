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

        if ($blocks->isEmpty()) {
            $blocks = Block::orderBy('block_num')->get();
        }

        $tasks = ApprenticeTask::where('apprentice_id', $apprentice->apprentice_id)
            ->with(['uploads'])
            ->orderBy('ablk_id')
            ->orderBy('task_num')
            ->get()
            ->groupBy('ablk_id');

        // Build Block Plan Work rows matching sample preview
        $blockPlanRows = $blocks->map(function ($block) use ($tasks) {
            $blockTasks = $tasks->get($block->block_num, collect());
            $loaded = $blockTasks->count();
            $approved = $blockTasks->where('status', 'Completed')->count();
            $waiting = $blockTasks->where('status', 'Submitted')->count();
            $changes = $blockTasks->where('status', 'Referred')->count();
            $percent = ($block->configured && $loaded > 0) ? round(($approved / $loaded) * 100) : null;

            $isOpen = $block->configured && ($block->start_date ? now()->gte($block->start_date) : true);
            $accessLabel = $block->configured ? ($isOpen ? 'Open' : 'Submissions locked') : 'Dates / configuration to check';

            return [
                'block' => $block->block_num,
                'title' => $block->title,
                'start' => $block->start_date ? $block->start_date->format('d-M-Y') : null,
                'end' => $block->end_date ? $block->end_date->format('d-M-Y') : null,
                'configured' => (bool) $block->configured,
                'access' => $accessLabel,
                'loaded' => $loaded,
                'approved' => $approved,
                'waiting' => $waiting,
                'changes' => $changes,
                'percent' => $percent,
                'confirmation' => $block->configured ? ($approved === 5 ? 'KSB confirmation approved by Lead Tutor' : 'KSB confirmation in progress') : 'Gateway/EPA task content pending',
                'canOpen' => $block->configured && $loaded > 0,
            ];
        });

        $changeRequests = \Illuminate\Support\Facades\DB::table('block_change_requests')
            ->where('apprentice_id', $apprentice->apprentice_id)
            ->orderByDesc('created_at')
            ->get();

        return view('tasks.index', compact('apprentice', 'blocks', 'tasks', 'blockPlanRows', 'changeRequests'));
    }

    /**
     * Submit a request for block opening or schedule date changes.
     */
    public function storeBlockRequest(Request $request, Apprentice $apprentice): RedirectResponse
    {
        $validated = $request->validate([
            'block_num' => 'required|integer|min:1|max:10',
            'request_type' => 'required|in:early,dates',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'reason' => 'required|string|max:1000',
        ]);

        \Illuminate\Support\Facades\DB::table('block_change_requests')->insert([
            'apprentice_id' => $apprentice->apprentice_id,
            'block_num' => $validated['block_num'],
            'request_type' => $validated['request_type'],
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['end_date'] ?? null,
            'reason' => $validated['reason'],
            'status' => 'Requested',
            'requested_by_user_id' => Auth::id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Block change request submitted for manager review.');
    }

    /**
     * Authorised manager records a decision on a block change request.
     */
    public function decideBlockRequest(Request $request, int $id): RedirectResponse
    {
        if (Auth::user()->role !== UserRole::Admin) {
            abort(403, 'Authorised manager / administrator review required.');
        }

        $validated = $request->validate([
            'decision' => 'required|in:Approve,Reject',
            'decision_reason' => 'required|string|max:1000',
        ]);

        \Illuminate\Support\Facades\DB::table('block_change_requests')
            ->where('id', $id)
            ->update([
                'status' => $validated['decision'] === 'Approve' ? 'Approved' : 'Rejected',
                'decision_reason' => $validated['decision_reason'],
                'decided_by_user_id' => Auth::id(),
                'updated_at' => now(),
            ]);

        return redirect()->back()->with('success', "Manager decision ({$validated['decision']}) recorded successfully.");
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

