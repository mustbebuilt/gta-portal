<?php

return [
    // Local/testing array-mail sink only. Live recovery requires a separate reviewed integration.
    'recovery_enabled' => env('GTA_PASSWORD_RECOVERY_ENABLED', false),
    // A separate, explicitly enabled staging SMTP pilot, never a production override.
    'staging_recovery_enabled' => env('GTA_STAGING_PASSWORD_RECOVERY_ENABLED', false),
    'staging_recipients' => array_values(array_filter(array_map('trim', explode(',', (string) env('GTA_STAGING_RECOVERY_RECIPIENTS', ''))))),
];
