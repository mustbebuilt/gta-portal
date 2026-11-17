<?php
return [
    // Return the organisation ID from the authenticated user's CURRENT authorised
    // employer/mentor membership. Never use request data. Null disables access.
    'organisation_resolver' => [\App\Support\EmployerResourceAccess::class, 'resolve'],
    // Existing authorised portal form URLs. Never point to a generic case list.
    'safeguarding_url' => env('GTA_EMPLOYER_SAFEGUARDING_URL'),
    'support_url' => env('GTA_EMPLOYER_SUPPORT_URL'),
];
