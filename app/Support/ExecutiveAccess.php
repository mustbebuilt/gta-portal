<?php

namespace App\Support;

use App\Models\ExecutiveAccessSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExecutiveAccess
{
    public static function current(Request $r): ?ExecutiveAccessSession
    {
        if (! $r->user()?->active || $r->user()->role !== 'ceo' || ! $r->hasSession() || ! $r->session()->has('gta_executive_reference')) {
            return null;
        }

        return ExecutiveAccessSession::where('reference', $r->session()->get('gta_executive_reference'))->where('user_id', $r->user()->id)->whereNull('ended_at')->first();
    }

    public static function enabled(Request $r): bool
    {
        return self::current($r) !== null;
    }

    public static function record(Request $r, string $action, string $reference): void
    {
        abort_unless($r->user()?->active && $r->user()->role === 'ceo' && $r->hasSession(), 403);
        $session = ExecutiveAccessSession::where('reference', $r->session()->get('gta_executive_reference'))->where('user_id', $r->user()->id)->whereNull('ended_at')->lockForUpdate()->first();
        abort_unless($session, 403);
        DB::table('executive_access_events')->insert(['executive_access_session_id' => $session->id, 'action' => $action, 'record_reference' => $reference, 'created_at' => now()]);
    }

    public static function end(Request $r): void
    {
        $session = self::current($r);
        if ($session) {
            DB::transaction(function () use ($session): void {
                $s = ExecutiveAccessSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
                if ($s->ended_at) {
                    return;
                }$s->ended_at = now();
                $s->save();
                DB::table('executive_access_events')->insert(['executive_access_session_id' => $s->id, 'action' => 'Emergency access ended', 'created_at' => now()]);
            });
        }
        if ($r->hasSession()) {
            $r->session()->forget('gta_executive_reference');
        }
    }
}
