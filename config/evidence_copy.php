<?php
return [
    'enabled'=>(bool)env('GTA_ENABLE_EVIDENCE_COPY',false),
    // Matches the largest current portal upload allowance; larger legacy objects need separate review.
    'max_object_bytes'=>20*1024*1024,
];
