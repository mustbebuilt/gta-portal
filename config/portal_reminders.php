<?php

return [
    // In-portal records only. No email, SMS or other external delivery.
    'scheduled' => (bool) env('GTA_SCHEDULE_PORTAL_REMINDERS', false),
];
