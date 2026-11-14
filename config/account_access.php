<?php

return [
    // Local/testing array-mail sink only. Live recovery requires a separate reviewed integration.
    'recovery_enabled' => env('GTA_PASSWORD_RECOVERY_ENABLED', false),
];
