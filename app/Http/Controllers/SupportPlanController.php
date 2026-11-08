<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\SupportPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class SupportPlanController extends Controller
{
    /**
     * Display the confidential Additional Learning Support (ALS) register.
     * Preserved Rule: Employers are strictly barred from viewing confidential support records.
     */
    public function index(Request $request): View
    {
        $user = Auth::user();

        // Strict Confidentiality Firewall
        if ($user->role === UserRole::Employer) {
            abort(403, 'Confidentiality Firewall: Employers are strictly excluded from private ALS support plans.');
        }

        if ($user->role === UserRole::Apprentice) {
            abort(403, 'Apprentices cannot view internal staff support registers.');
        }

        $plans = SupportPlan::with(['apprentice.user', 'planLead', 'actions'])
            ->orderByDesc('created_at')
            ->get();

        return view('support.index', compact('plans'));
    }

    /**
     * Display a single support plan and authorized teaching instructions.
     */
    public function show(SupportPlan $supportPlan): View
    {
        $user = Auth::user();

        // Strict Confidentiality Firewall
        if ($user->role === UserRole::Employer) {
            abort(403, 'Confidentiality Firewall: Employers are strictly excluded from private ALS support plans.');
        }

        if ($user->role === UserRole::Apprentice) {
            abort(403, 'Access denied.');
        }

        $supportPlan->load(['apprentice.user', 'planLead', 'actions']);

        // Check if user is an Administrator or the designated ALS Lead
        $isLeadOrAdmin = in_array($user->role, [UserRole::Admin, UserRole::Moderator], true)
            || $user->id === $supportPlan->plan_lead_user_id;

        return view('support.show', compact('supportPlan', 'isLeadOrAdmin'));
    }
}

