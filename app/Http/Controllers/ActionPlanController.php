<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\ActionPlan;
use App\Models\ActionPlanItem;
use App\Models\Apprentice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ActionPlanController extends Controller
{
    /**
     * Display the Action Plan matching action-plan.html.
     */
    public function index(Request $request, ?Apprentice $apprentice = null): View
    {
        $user = Auth::user();

        if (!$apprentice || !$apprentice->exists) {
            if ($user->role === UserRole::Apprentice) {
                $apprentice = Apprentice::where('user_id', $user->id)->firstOrFail();
            } else {
                $apprenticeId = $request->input('apprentice', 1);
                $apprentice = Apprentice::find($apprenticeId) ?? Apprentice::where('status', 'Live')->first();
            }
        }

        // Resolve or create ActionPlan container
        $plan = ActionPlan::firstOrCreate([
            'apprentice_id' => $apprentice->apprentice_id,
        ], [
            'created_by_user_id' => $user->id,
        ]);

        $todoItems = ActionPlanItem::where('action_plan_id', $plan->action_plan_id)
            ->where('status', 'To do')
            ->orderBy('target_date')
            ->get();

        $completedItems = ActionPlanItem::where('action_plan_id', $plan->action_plan_id)
            ->where('status', 'Completed')
            ->orderByDesc('completed_at')
            ->get();

        $allApprentices = $user->isStaff()
            ? Apprentice::with('user')->where('status', 'Live')->get()
            : collect();

        return view('action-plan.index', compact(
            'apprentice',
            'plan',
            'todoItems',
            'completedItems',
            'allApprentices'
        ));
    }

    /**
     * Store a new SMART action plan target.
     */
    public function store(Request $request, Apprentice $apprentice): RedirectResponse
    {
        $user = Auth::user();

        $validated = $request->validate([
            'action_description' => 'required|string',
            'owner_role' => 'required|in:Apprentice,Tutor,Employer / workplace mentor,Learning & Progress Mentor',
            'target_date' => 'required|date',
        ]);

        $plan = ActionPlan::firstOrCreate([
            'apprentice_id' => $apprentice->apprentice_id,
        ], [
            'created_by_user_id' => $user->id,
        ]);

        ActionPlanItem::create([
            'action_plan_id' => $plan->action_plan_id,
            'action_description' => $validated['action_description'],
            'owner_role' => $validated['owner_role'],
            'target_date' => $validated['target_date'],
            'status' => 'To do',
        ]);

        return redirect()->route('action-plan.index', ['apprentice' => $apprentice->apprentice_id])
            ->with('success', 'SMART target added to Action Plan.');
    }

    /**
     * Mark an action target as completed.
     */
    public function complete(ActionPlanItem $item): RedirectResponse
    {
        $item->update([
            'status' => 'Completed',
            'completed_at' => now(),
        ]);

        return back()->with('success', 'Action marked as completed.');
    }
}

