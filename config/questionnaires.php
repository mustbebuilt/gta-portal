<?php

return [
    'enabled' => env('GTA_QUESTIONNAIRES_ENABLED', false),
    // Exact lowercase hosts, no wildcards. Configure only the organisation's reviewed form hosts.
    'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('GTA_QUESTIONNAIRE_HOSTS', ''))))),
    'links' => [
        'induction' => ['url' => env('GTA_QUESTIONNAIRE_INDUCTION_URL'), 'reviewed_sha256' => env('GTA_QUESTIONNAIRE_INDUCTION_REVIEWED_SHA256')],
        'sixweek' => ['url' => env('GTA_QUESTIONNAIRE_SIXWEEK_URL'), 'reviewed_sha256' => env('GTA_QUESTIONNAIRE_SIXWEEK_REVIEWED_SHA256')],
        'ongoing' => ['url' => env('GTA_QUESTIONNAIRE_ONGOING_URL'), 'reviewed_sha256' => env('GTA_QUESTIONNAIRE_ONGOING_REVIEWED_SHA256')],
    ],
];
