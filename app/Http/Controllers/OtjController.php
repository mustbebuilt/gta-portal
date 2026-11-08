<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Apprentice;
use App\Models\Otj;
use App\Services\OtjCalculationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class OtjController extends Controller
{
    public function __construct(
        protected OtjCalculationService $otjService
    ) {}

    /**
     * View Off-The-Job training dashboard and ledger for an apprentice.
     */
    public function index(Request $request, ?Apprentice $apprentice = null): View
    {
        $user = Auth::user();

        if (!$apprentice || !$apprentice->exists) {
            if ($user->role === UserRole::Apprentice) {
                $apprentice = Apprentice::where('user_id', $user->id)->firstOrFail();
            } elseif ($user->role === UserRole::Employer) {
                $employer = \App\Models\Employer::where('contact_email', $user->email)->first();
                $apprentice = Apprentice::where('employer_id', $employer?->employer_id ?? -1)->where('status', 'Live')->firstOrFail();
            } else {
                $apprentice = Apprentice::where('status', 'Live')->firstOrFail();
            }
        }

        Gate::authorize('view', $apprentice);

        $metrics = $this->otjService->computeMetrics($apprentice);

        $entries = Otj::where('uid', $apprentice->user_id)
            ->orderByDesc('date_of_otj')
            ->orderByDesc('created_at')
            ->get();

        return view('otj.index', compact('apprentice', 'metrics', 'entries'));
    }

    /**
     * Log a new OTJ training activity.
     */
    public function store(Request $request, Apprentice $apprentice): RedirectResponse
    {
        $user = Auth::user();

        $request->validate([
            'date_of_otj' => ['required', 'date'],
            'hours' => ['required', 'numeric', 'min:0.25', 'max:24.0'],
            'otj_type' => ['required', 'string'],
            'otj_activity_description' => ['required', 'string', 'min:10', 'max:1000'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
        ]);

        // Convert decimal hours into 15-minute chunks (e.g. 6.0 hrs = 24 chunks)
        $timeChunks = (int) round($request->input('hours') * 4);

        // Tutors can directly verify; learner logs are pending sign-off
        $autoVerify = in_array($user->role, [UserRole::Trainer, UserRole::Admin], true);

        Otj::create([
            'uid' => $apprentice->user_id,
            'time_chunks' => $timeChunks,
            'date_of_otj' => $request->input('date_of_otj'),
            'start_time' => $request->input('start_time'),
            'end_time' => $request->input('end_time'),
            'otj_type' => $request->input('otj_type'),
            'otj_activity_description' => $request->input('otj_activity_description'),
            'otj_trainer_sign_off' => $autoVerify,
        ]);

        // Update cached completed hours on apprentice model
        $verifiedHours = Otj::where('uid', $apprentice->user_id)
            ->where('otj_trainer_sign_off', true)
            ->sum('time_chunks') * 0.25;

        $apprentice->update(['otj_hours_completed' => $verifiedHours]);

        return redirect()->back()->with('success', 'Off-The-Job training entry recorded successfully.');
    }

    /**
     * Tutor signs off on an apprentice's logged OTJ entry.
     */
    public function verify(Request $request, Otj $otj): RedirectResponse
    {
        $user = Auth::user();

        if (!in_array($user->role, [UserRole::Trainer, UserRole::Admin], true)) {
            abort(403, 'Only a GTA tutor or administrator can verify OTJ hours.');
        }

        $otj->update(['otj_trainer_sign_off' => true]);

        // Recalculate apprentice verified hours
        $apprentice = Apprentice::where('user_id', $otj->uid)->first();
        if ($apprentice) {
            $verifiedHours = Otj::where('uid', $apprentice->user_id)
                ->where('otj_trainer_sign_off', true)
                ->sum('time_chunks') * 0.25;

            $apprentice->update(['otj_hours_completed' => $verifiedHours]);
        }

        return redirect()->back()->with('success', 'OTJ training entry verified and signed off.');
    }
}
