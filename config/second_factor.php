<?php

return [
    // Activate after verified staff enrolment and recovery checks; see Pack 85 handover.
    'required_for_support' => env('GTA_MFA_REQUIRED_FOR_SUPPORT', false),
    // Controls NEW enrolment only. Confirmed factors remain enforced when this is OFF.
    'enrolment_enabled' => env('GTA_MFA_ENROLMENT_ENABLED', false),
    'pilot_emails' => array_values(array_filter(array_map(fn ($value) => strtolower(trim($value)),
        explode(',', (string) env('GTA_MFA_PILOT_EMAILS', ''))))),
];
