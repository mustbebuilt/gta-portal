<?php

return [
    'api_key' => env('RESEND_API_KEY'),
    // An unused or unsigned webhook must never be registered by default.
    'routes' => env('RESEND_ROUTES', false) && filled(env('RESEND_WEBHOOK_SECRET')),
    'domain' => env('RESEND_DOMAIN', null),
    'path' => env('RESEND_PATH', 'resend'),
    'webhook' => ['secret' => env('RESEND_WEBHOOK_SECRET'), 'tolerance' => env('RESEND_WEBHOOK_TOLERANCE', 300)],
    'middleware' => [],
];
