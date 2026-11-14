<?php

return [
    // Controls NEW enrolment only. Confirmed factors remain enforced when this is OFF.
    'enrolment_enabled' => env('GTA_MFA_ENROLMENT_ENABLED', false),
    'pilot_emails' => array_values(array_filter(array_map(fn ($value) => strtolower(trim($value)),
        explode(',', (string) env('GTA_MFA_PILOT_EMAILS', ''))))),
];
