<?php

return [
    'disk' => env('GTA_PRIVATE_EVIDENCE_DISK', 'local'),
    // Keep the old location until a separately verified file migration is performed.
    'legacy_disk' => env('GTA_LEGACY_EVIDENCE_DISK', 'local'),
    'private_disks' => array_values(array_filter(array_map('trim', explode(',', env('GTA_PRIVATE_EVIDENCE_ALLOWED_DISKS', 'local'))))),
];
