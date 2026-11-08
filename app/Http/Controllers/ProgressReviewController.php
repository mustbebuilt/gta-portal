<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Apprentice;
use App\Models\ProgressReview;
use App\Models\ReviewSignature;
use App\Services\ReviewScheduleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProgressReviewController extends Controller
{
    public function __construct(
        protected ReviewScheduleService $scheduleService
    ) {}

    /**
     * List all 10-week and 70-day progress reviews for an apprentice.
     */
    public function index(Request $request, ?Apprentice $apprentice = null): View
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

        $reviews = ProgressReview::where('apprentice_id', $apprentice->apprentice_id)
            ->with(['trainer', 'signatures'])
            ->orderBy('review_sequence')
            ->get();

        $nextDueDate = $this->scheduleService->calculateNextDueDate($apprentice);

        return view('reviews.index', compact('apprentice', 'reviews', 'nextDueDate'));
    }

    /**
     * Display a single review document with tripartite signature status.
     */
    public function show(ProgressReview $review): View
    {
        $review->load(['apprentice.user', 'apprentice.employer', 'trainer', 'signatures.user']);
        Gate::authorize('view', $review->apprentice);

        $signaturesByRole = $review->signatures->keyBy('party_role');

        return view('reviews.show', compact('review', 'signaturesByRole'));
    }

    /**
     * Schedule a new progress review using the 10-week / 70-day cadence.
     */
    public function schedule(Request $request, Apprentice $apprentice): RedirectResponse
    {
        $user = Auth::user();

        if (!$user->isStaff()) {
            abort(403, 'Only staff can schedule progress reviews.');
        }

        $lastSeq = ProgressReview::where('apprentice_id', $apprentice->apprentice_id)->max('review_sequence') ?? 0;
        $nextSeq = $lastSeq + 1;
        $dueDate = $this->scheduleService->calculateNextDueDate($apprentice);

        ProgressReview::create([
            'apprentice_id' => $apprentice->apprentice_id,
            'trainer_id' => $apprentice->trainer_id ?: $user->id,
            'review_sequence' => $nextSeq,
            'due_date' => $dueDate->toDateString(),
            'status' => 'Scheduled',
        ]);

        return redirect()->back()->with('success', "Progress Review #{$nextSeq} scheduled for {$dueDate->format('d M Y')}.");
    }

    /**
     * Conduct the meeting and record agreed progress discussion.
     */
    public function conduct(Request $request, ProgressReview $review): RedirectResponse
    {
        $user = Auth::user();

        if (!$user->isStaff()) {
            abort(403, 'Only GTA staff can record meeting outcomes.');
        }

        $request->validate([
            'meeting_date' => ['required', 'date'],
            'progress_summary' => ['required', 'string', 'min:15'],
        ]);

        $review->update([
            'meeting_date' => $request->input('meeting_date'),
            'progress_summary' => $request->input('progress_summary'),
            'status' => 'Conducted',
        ]);

        return redirect()->back()->with('success', 'Progress review discussion recorded. Ready for tripartite signatures.');
    }

    /**
     * Sign the review as Apprentice, Employer, or Progress Mentor.
     */
    public function sign(Request $request, ProgressReview $review): RedirectResponse
    {
        $user = Auth::user();
        $request->validate([
            'signatory_name' => ['required', 'string', 'min:3', 'max:150'],
        ]);

        // Determine party role based on user account
        $partyRole = match ($user->role) {
            UserRole::Apprentice => 'apprentice',
            UserRole::Employer => 'employer',
            default => 'mentor',
        };

        // Prevent unauthorized signature attempts
        if ($partyRole === 'apprentice' && $user->id !== $review->apprentice->user_id) {
            abort(403, 'You can only sign your own apprentice review.');
        }

        $docHash = $this->scheduleService->generateDocumentHash($review);

        ReviewSignature::updateOrCreate(
            [
                'review_id' => $review->id,
                'party_role' => $partyRole,
            ],
            [
                'user_id' => $user->id,
                'signatory_name' => $request->input('signatory_name'),
                'signed_at' => now(),
                'ip_address' => $request->ip(),
                'document_snapshot_hash' => $docHash,
            ]
        );

        // If all 3 distinct parties have signed, update review status to Signed
        $signedPartiesCount = ReviewSignature::where('review_id', $review->id)->count();
        if ($signedPartiesCount >= 3) {
            $review->update(['status' => 'Signed']);
        }

        return redirect()->back()->with('success', 'Your digital signature has been verified and recorded.');
    }
}

