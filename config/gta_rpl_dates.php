<?php

$enabled = array_map('trim', explode(',', (string) env('GTA_RPL_DATE_PROGRAMMES', '')));
$supportedVersions = ['ST0499:1.3', 'ST0068:1.5', 'ST0403:1.3', 'ST0448:1.2'];

return array_fill_keys(array_intersect($supportedVersions, $enabled), [
    'hours_per_training_week' => 6,
    'training_weeks_per_year' => 46.4,
    'epa_months' => 1,
]);
