<?php

return [
    // In-portal records only. No email, SMS or other external delivery.
    'support_plan_scheduled' => env('GTA_SCHEDULE_SUPPORT_PLAN_REMINDERS', false),
    'scheduled' => (bool) env('GTA_SCHEDULE_PORTAL_REMINDERS', false),
];
