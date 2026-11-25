<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SecurityAudit85
{
    public static function record(Request $request, string $event, array $context = [], ?string $email = null): void
    {
        // Neither passwords, private narratives, query strings nor raw email/IP addresses enter this log.
        DB::table('portal_security_events')->insert([
            'user_id' => $request->user()?->id,
            'event' => $event,
            'principal_hash' => $email === null ? null : hash_hmac('sha256', strtolower(trim($email)), (string) config('app.key')),
            'network_hash' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
            'context' => json_encode($context, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
