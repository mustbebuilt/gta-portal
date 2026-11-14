<?php

return [
    'enabled' => (bool) env('GTA_APPRENTICE_ALERTS_ENABLED', false),
    'scheduled' => (bool) env('GTA_APPRENTICE_ALERTS_SCHEDULED', false),
    'email_enabled' => (bool) env('GTA_APPRENTICE_ALERT_EMAIL_ENABLED', false),
    'sms_enabled' => (bool) env('GTA_APPRENTICE_ALERT_SMS_ENABLED', false),
    'emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('GTA_APPRENTICE_ALERT_EMAIL_RECIPIENTS', ''))))),
    'phones' => array_values(array_filter(array_map('trim', explode(',', (string) env('GTA_APPRENTICE_ALERT_SMS_RECIPIENTS', ''))))),
    'attempt_limit' => (int) env('GTA_APPRENTICE_ALERT_MAX_ATTEMPTS_24H', 50),
    'key' => env('VONAGE_KEY'), 'secret' => env('VONAGE_SECRET'), 'from' => env('VONAGE_SMS_FROM'),
];
