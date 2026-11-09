<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Apprentice;
use App\Models\CareerDiscussion;
use App\Models\CareerResource;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class CareerController extends Controller
{
    /**
     * Display Careers dashboard, advice history, and resources.
     * Preserved Rule: Employers are strictly barred from careers notes.
     */
    public function index(Request $request, ?Apprentice $apprentice = null): View
    {
        $user = Auth::user();

        // 1. Strict Employer Confidentiality Firewall
        if ($user->role === UserRole::Employer) {
            abort(403, 'Confidentiality Firewall: Employers are strictly excluded from Apprentice Careers notes and guidance records.');
        }

        // 2. Resolve apprentice
        if (!$apprentice || !$apprentice->exists) {
            if ($user->role === UserRole::Apprentice) {
                $apprentice = Apprentice::where('user_id', $user->id)->firstOrFail();
            } else {
                $apprentice = Apprentice::where('status', 'Live')->first();
            }
        }

        $discussions = $apprentice
            ? CareerDiscussion::where('apprentice_id', $apprentice->apprentice_id)->with('adviser')->orderByDesc('discussion_date')->get()
            : collect();

        $resources = CareerResource::all();

        // Work list for staff (Block 10 progression trigger or all active learners)
        $allApprentices = ($user->isStaff())
            ? Apprentice::with(['user', 'employer'])->where('status', 'Live')->get()
            : collect();

        return view('careers.index', compact('apprentice', 'discussions', 'resources', 'allApprentices'));
    }

    /**
     * Record a new career progression discussion.
     */
    public function store(Request $request, Apprentice $apprentice): RedirectResponse
    {
        $user = Auth::user();

        if ($user->role === UserRole::Employer) {
            abort(403, 'Employers cannot record career discussions.');
        }

        $validated = $request->validate([
            'discussion_type' => 'required|in:progression,employment',
            'discussion_date' => 'required|date',
            'goals' => 'required|string',
            'options_discussed' => 'required|string',
            'advice_given' => 'required|string',
            'outcome' => 'required|string',
            'referral_to' => 'nullable|string',
            'next_steps' => 'required|string',
        ]);

        CareerDiscussion::create([
            'apprentice_id' => $apprentice->apprentice_id,
            'adviser_id' => $user->id,
            'discussion_type' => $validated['discussion_type'],
            'discussion_date' => $validated['discussion_date'],
            'goals' => $validated['goals'],
            'options_discussed' => $validated['options_discussed'],
            'advice_given' => $validated['advice_given'],
            'outcome' => $validated['outcome'],
            'referral_to' => $validated['referral_to'] ?? null,
            'next_steps' => $validated['next_steps'],
        ]);

        return redirect()->route('careers.index', ['apprentice' => $apprentice->apprentice_id])
            ->with('success', 'Career discussion recorded successfully.');
    }
}

