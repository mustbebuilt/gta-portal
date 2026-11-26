<?php

namespace App\Support;

final class ReportedChoices
{
    public const MARKING_REFERRAL = [
        'missing-evidence' => 'Evidence missing',
        'unreadable-evidence' => 'Evidence unreadable or unclear',
        'contribution-unclear' => 'Apprentice’s contribution unclear',
        'incomplete-work' => 'Part of the work incomplete',
        'accuracy' => 'Accuracy needs correcting',
        'missing-working' => 'Method or working needs explaining',
        'requirement-not-met' => 'Assessment requirement not yet met',
        'other' => 'Other',
    ];

    public const SUPPORT_REVIEW = [
        'continue-support' => 'Continue current support',
        'change-support' => 'Change to support agreed',
        'monitor' => 'Monitor at the next review',
        'information-needed' => 'Further information needed',
        'closure-review' => 'Check whether the marker can close',
        'other' => 'Other',
    ];

    public const REGISTER_CANCELLATION = [
        'gta-closed' => 'GTA closed',
        'staff-unavailable' => 'Tutor or cover unavailable',
        'room-unavailable' => 'Room or workshop unavailable',
        'timetable-change' => 'Timetable change',
        'disruption' => 'Severe weather or travel disruption',
        'other' => 'Other',
    ];
}
