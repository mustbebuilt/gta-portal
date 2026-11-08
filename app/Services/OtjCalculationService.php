<?php

namespace App\Services;

use App\Models\Apprentice;
use App\Models\Otj;
use Carbon\Carbon;

class OtjCalculationService
{
    public const RAG_GREEN = 'green';
    public const RAG_AMBER = 'amber';
    public const RAG_RED = 'red';
    public const RAG_UNKNOWN = 'unknown';

    /**
     * Compute comprehensive OTJ hours metrics.
     * 1 time_chunk = 15 minutes (0.25 hours).
     * 24 chunks = 6.0 hours (GTA standard specialist-day reference).
     */
    public function computeMetrics(Apprentice $apprentice): array
    {
        $userId = $apprentice->user_id;

        // Total and signed-off chunks from the ledger
        $totalChunks = Otj::where('uid', $userId)->sum('time_chunks');
        $verifiedChunks = Otj::where('uid', $userId)->where('otj_trainer_sign_off', true)->sum('time_chunks');

        $totalLoggedHours = round($totalChunks * 0.25, 2);
        $verifiedHours = round($verifiedChunks * 0.25, 2);
        $pendingHours = round(($totalChunks - $verifiedChunks) * 0.25, 2);

        $targetHours = (float) ($apprentice->otj_target_hours > 0 ? $apprentice->otj_target_hours : 663.00);

        // Overall programme completion percentage
        $completionPercentage = round(($verifiedHours / $targetHours) * 100, 1);

        // Expected-to-date calculation based on elapsed training duration
        $expectedHoursToDate = $this->calculateExpectedHoursToDate($apprentice, $targetHours);

        // Expected vs Actual RAG status
        $rag = $this->computeRag($verifiedHours, $expectedHoursToDate);

        return [
            'target_hours' => $targetHours,
            'total_logged_hours' => $totalLoggedHours,
            'verified_hours' => $verifiedHours,
            'pending_hours' => $pendingHours,
            'completion_percentage' => $completionPercentage,
            'expected_to_date' => $expectedHoursToDate,
            'rag_rating' => $rag['rating'],
            'rag_label' => $rag['label'],
            'rag_badge_class' => $rag['badge_class'],
        ];
    }

    protected function calculateExpectedHoursToDate(Apprentice $apprentice, float $targetHours): ?float
    {
        if (!$apprentice->start_date || !$apprentice->planned_end_date) {
            return null;
        }

        $start = Carbon::parse($apprentice->start_date);
        $end = Carbon::parse($apprentice->planned_end_date);
        $now = Carbon::now();

        if ($now->lt($start)) {
            return 0.0;
        }

        $totalDays = $start->diffInDays($end) ?: 1;
        $elapsedDays = min($start->diffInDays($now), $totalDays);

        $expected = round(($elapsedDays / $totalDays) * $targetHours, 1);

        return $expected;
    }

    protected function computeRag(float $verifiedHours, ?float $expectedHours): array
    {
        if ($expectedHours === null || $expectedHours <= 0) {
            return [
                'rating' => self::RAG_UNKNOWN,
                'label' => 'Pending Baseline',
                'badge_class' => 'badge',
            ];
        }

        $ratio = ($verifiedHours / $expectedHours) * 100;

        $rating = match (true) {
            $ratio >= 100.0 => self::RAG_GREEN,
            $ratio >= 90.0 => self::RAG_AMBER,
            default => self::RAG_RED,
        };

        $badgeClass = match ($rating) {
            self::RAG_GREEN => 'badge-green',
            self::RAG_AMBER => 'badge-amber',
            default => 'badge-red',
        };

        return [
            'rating' => $rating,
            'label' => round($ratio, 1) . '% of Expected',
            'badge_class' => $badgeClass,
        ];
    }
}
