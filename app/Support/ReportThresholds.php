<?php

namespace App\Support;

final class ReportThresholds
{
    public const LABELS = [
        'green' => 'On track', 'amber' => 'Watch', 'red' => 'Action needed',
        'grey' => 'No rating',
    ];

    public static function internal(?float $value, ?string $measure, ?int $denominator = null): array
    {
        if ($measure === 'attendance') {
            $reason = 'Green at 90% or higher, amber from 85% to below 90%, red below 85%. At least 10 expected apprentice-sessions with complete marks.';
            $status = $value === null || ($denominator ?? 0) < 10 ? 'grey'
                : ($value >= 90 ? 'green' : ($value >= 85 ? 'amber' : 'red'));
        } elseif ($measure === 'exceptions') {
            $reason = 'Green at zero, amber for 1 to 4, red at 5 or more. Follow up individual exceptions even when the total is low.';
            $status = $value === null ? 'grey' : ($value === 0.0 ? 'green' : ($value < 5 ? 'amber' : 'red'));
        } else {
            $reason = 'Activity or outcome count for context.';
            $status = 'grey';
        }

        return ['status' => $status, 'label' => self::LABELS[$status], 'rule' => $reason];
    }

    public static function benchmarks(): array
    {
        return [
            ['Achievement rate (QAR)', '60% or above', '50% to below 60%', 'Below 50%'],
            ['Retention rate (QAR)', '62% or above', '52% to below 62%', 'Below 52%'],
            ['Withdrawals', 'Below 15%', '15% to below 20%', '20% or above'],
            ['Past planned end date, still in learning', 'Below 10%', '10% to below 15%', '15% or above'],
            ['Employer feedback', '2.5 or above', 'Below 2.5', 'No red band'],
            ['Apprentice feedback', '2.5 or above', 'Below 2.5', 'No red band'],
        ];
    }
}
