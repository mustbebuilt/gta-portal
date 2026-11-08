<?php

namespace App\Services;

use App\Models\Apprentice;
use App\Models\ProgressReview;
use Carbon\Carbon;

class ReviewScheduleService
{
    /**
     * Preserved Rule from RULES_AND_PERMISSIONS.txt:
     * - First review due: 10 weeks from first recorded day in learning (start_date).
     * - Subsequent reviews due: 70 calendar days after the actual meeting date (not signature date).
     */
    public function calculateNextDueDate(Apprentice $apprentice): Carbon
    {
        $lastReview = ProgressReview::where('apprentice_id', $apprentice->apprentice_id)
            ->whereNotNull('meeting_date')
            ->orderByDesc('review_sequence')
            ->first();

        if (!$lastReview) {
            $startDate = $apprentice->start_date ?: now();
            return Carbon::parse($startDate)->addWeeks(10);
        }

        return Carbon::parse($lastReview->meeting_date)->addDays(70);
    }

    /**
     * Generate a cryptographic SHA-256 snapshot hash of the review document.
     */
    public function generateDocumentHash(ProgressReview $review): string
    {
        $data = [
            'id' => $review->id,
            'apprentice_id' => $review->apprentice_id,
            'sequence' => $review->review_sequence,
            'meeting_date' => $review->meeting_date?->toDateString(),
            'progress_summary' => $review->progress_summary,
        ];

        return hash('sha256', json_encode($data));
    }
}

