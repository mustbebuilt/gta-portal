<?php
return [
 'enabled'=>(bool)env('GTA_SMS_MOBILE_VERIFICATION_ENABLED',false),
 'recipients'=>array_values(array_filter(array_map('trim',explode(',',(string)env('GTA_SMS_MOBILE_VERIFICATION_RECIPIENTS',''))))),
 'key'=>env('VONAGE_KEY'),'secret'=>env('VONAGE_SECRET'),'from'=>env('VONAGE_SMS_FROM'),
];
