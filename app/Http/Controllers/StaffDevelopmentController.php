<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\StaffDevelopmentRecord;
use App\Models\StaffDevelopmentTarget;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class StaffDevelopmentController extends Controller
{
    /**
     * Display the Staff Development dashboard.
     * Preserved Rule: Staff & Administrator only.
     */
    public function index(Request $request): View
    {
        $currentUser = Auth::user();

        if (!$currentUser->isStaff()) {
            abort(403, 'Access Restricted: Staff development is accessible to training and administrative staff only.');
        }

        // Selected staff member (defaults to current user or first tutor if admin)
        $selectedUserId = $request->input('user_id', $currentUser->id);
        $selectedStaff = User::findOrFail($selectedUserId);

        // All teaching/administrative staff in scope
        $staffMembers = User::whereIn('role', [
            UserRole::Trainer,
            UserRole::Moderator,
            UserRole::Mentor,
            UserRole::Admin,
        ])->orderBy('first_name')->get();

        // Staff records (CPD, observations, one-to-ones)
        $query = StaffDevelopmentRecord::where('user_id', $selectedStaff->id);
        if ($currentUser->id === $selectedStaff->id && $currentUser->role !== UserRole::Admin) {
            // Non-admin staff viewing own record sees only shared entries
            $query->where('is_shared', true);
        }
        $records = $query->orderByDesc('record_date')->get();

        // Active SMART targets
        $targets = StaffDevelopmentTarget::where('user_id', $selectedStaff->id)->orderBy('deadline')->get();

        // Team stats for Admins
        $totalCpdHours = $records->where('category', 'cpd')->count();
        $totalObservations = $records->where('category', 'observation')->count();
        $pendingTargets = $targets->where('status', 'In Progress')->count();

        return view('staff-development.index', compact(
            'selectedStaff',
            'staffMembers',
            'records',
            'targets',
            'totalCpdHours',
            'totalObservations',
            'pendingTargets'
        ));
    }

    /**
     * Store a CPD or Teaching Observation record.
     */
    public function storeRecord(Request $request, User $user): RedirectResponse
    {
        $currentUser = Auth::user();

        $validated = $request->validate([
            'category' => 'required|in:overview,performance,cpd,observation,meetings',
            'title' => 'required|string|max:255',
            'record_date' => 'required|date',
            'feedback' => 'nullable|string',
            'evidence_ref' => 'nullable|string|max:100',
            'is_shared' => 'nullable|boolean',
            'private_notes' => 'nullable|string',
        ]);

        StaffDevelopmentRecord::create([
            'user_id' => $user->id,
            'manager_id' => $currentUser->id,
            'category' => $validated['category'],
            'title' => $validated['title'],
            'record_date' => $validated['record_date'],
            'feedback' => $validated['feedback'] ?? null,
            'evidence_ref' => $validated['evidence_ref'] ?? null,
            'is_shared' => $request->has('is_shared'),
            'private_notes' => $validated['private_notes'] ?? null,
            'status' => 'Completed',
        ]);

        return redirect()->route('staff-development.index', ['user_id' => $user->id])
            ->with('success', 'Staff development record logged.');
    }

    /**
     * Store an agreed SMART development target.
     */
    public function storeTarget(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'target' => 'required|string',
            'measure' => 'nullable|string',
            'reason' => 'nullable|string',
            'support_provided' => 'nullable|string',
            'deadline' => 'nullable|date',
        ]);

        StaffDevelopmentTarget::create([
            'user_id' => $user->id,
            'target' => $validated['target'],
            'measure' => $validated['measure'] ?? null,
            'reason' => $validated['reason'] ?? null,
            'support_provided' => $validated['support_provided'] ?? null,
            'deadline' => $validated['deadline'] ?? null,
            'status' => 'In Progress',
        ]);

        return redirect()->route('staff-development.index', ['user_id' => $user->id])
            ->with('success', 'Development target agreed and recorded.');
    }
}

