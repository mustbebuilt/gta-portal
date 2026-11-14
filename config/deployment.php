<?php

return [
    'staging_enabled' => (bool) env('GTA_ENABLE_STAGING_PORTAL', false),
    'initial_admin_enabled' => (bool) env('GTA_ALLOW_INITIAL_ADMIN', false),
];
