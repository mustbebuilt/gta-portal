<?php

namespace App\Services;

use App\Models\Apprentice;
use App\Models\AttendanceRecord;

class AttendanceRagService
{
    public const RAG_GREEN = 'green';
    public const RAG_AMBER = 'amber';
    public const RAG_RED = 'red';
    public const RAG_UNKNOWN = 'unknown';

    /**
     * Compute Attendance RAG indicator.
     * Preserved Rule from RULES_AND_PERMISSIONS.txt:
     * - Green: >= 95.0%
     * - Amber: >= 90.0% and < 95.0%
     * - Red: < 90.0%
     * - Complete Denominator Rule: Unknown sessions/no data returns UNKNOWN, never an assumed Green or 0.
     */
    public function computeRag(Apprentice $apprentice): array
    {
        $totalSessions = AttendanceRecord::where('apprentice_id', $apprentice->apprentice_id)->count();

        if ($totalSessions === 0) {
            return [
                'total_sessions' => 0,
                'attended_sessions' => 0,
                'percentage' => null,
                'rating' => self::RAG_UNKNOWN,
                'label' => 'Unknown (No sessions)',
                'badge_class' => 'badge',
                'badge_style' => 'background: #f1f5f9; color: #64748b;',
            ];
        }

        $attendedSessions = AttendanceRecord::where('apprentice_id', $apprentice->apprentice_id)
            ->whereIn('status', ['Present', 'Late'])
            ->count();

        $percentage = round(($attendedSessions / $totalSessions) * 100, 1);

        $rating = match (true) {
            $percentage >= 95.0 => self::RAG_GREEN,
            $percentage >= 90.0 => self::RAG_AMBER,
            default => self::RAG_RED,
        };

        $badgeClass = match ($rating) {
            self::RAG_GREEN => 'badge-green',
            self::RAG_AMBER => 'badge-amber',
            default => 'badge-red',
        };

        return [
            'total_sessions' => $totalSessions,
            'attended_sessions' => $attendedSessions,
            'percentage' => $percentage,
            'rating' => $rating,
            'label' => "{$percentage}% (" . strtoupper($rating) . ")",
            'badge_class' => $badgeClass,
            'badge_style' => '',
        ];
    }
}
