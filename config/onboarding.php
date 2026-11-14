<?php
return [
    'enabled' => env('GTA_STAGING_ONBOARDING_ENABLED', false),
    'recipients' => array_values(array_filter(array_map('trim', explode(',', (string) env('GTA_STAGING_ONBOARDING_RECIPIENTS', ''))))),
];
