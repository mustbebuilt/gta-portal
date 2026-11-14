<?php
return [
    'enabled' => env('GTA_ENABLE_EVIDENCE_RESTORE_REHEARSAL', false),
    'targets' => array_values(array_filter(array_map('trim', explode(',', (string) env('GTA_EVIDENCE_RESTORE_TARGET_DISKS', ''))))),
    'max_objects' => 100,
    'max_object_bytes' => 20 * 1024 * 1024,
];
