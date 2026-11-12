<?php

namespace App\Http\Controllers;

use App\Models\ExecutiveAccessSession;
use App\Support\ExecutiveAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ExecutiveAccessController extends Controller
{
    public function index(Request $r): View
    {
        abort_unless($r->user()->role === 'ceo', 403);
        $sessions = ExecutiveAccessSession::where('user_id', $r->user()->id)->orderByDesc('id')->limit(30)->get();

        return view('executive.index', ['active' => ExecutiveAccess::current($r), 'sessions' => $sessions, 'events' => DB::table('executive_access_events')->whereIn('executive_access_session_id', $sessions->pluck('id'))->orderBy('id')->get()->groupBy('executive_access_session_id')]);
    }

    public function begin(Request $r): RedirectResponse
    {
        abort_unless($r->user()->role === 'ceo', 403);
        if (ExecutiveAccess::enabled($r)) {
            throw ValidationException::withMessages(['reason' => 'End the current emergency session before starting another.']);
        }
        $key = 'executive-confirm:'.$r->user()->id.'|'.hash('sha256', (string) $r->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['password' => 'Too many attempts. Try again in a minute.']);
        }
        RateLimiter::hit($key, 60);
        $data = $r->validate(['reason' => ['required', 'string', 'max:2000'], 'password' => ['required', 'current_password'], 'confirmed' => ['accepted']]);
        $session = DB::transaction(function () use ($r, $data) {
            $s = ExecutiveAccessSession::create(['user_id' => $r->user()->id, 'reference' => (string) Str::uuid(), 'reason' => $data['reason'], 'started_at' => now()]);
            DB::table('executive_access_events')->insert(['executive_access_session_id' => $s->id, 'action' => 'Emergency access enabled', 'created_at' => now()]);

            return $s;
        });
        RateLimiter::clear($key);
        $r->session()->regenerate();
        $r->session()->put('gta_executive_reference', $session->reference);

        return to_route('executive.index')->with('status', 'Emergency access enabled for this signed-in CEO session. Reasons and uses are recorded.');
    }

    public function end(Request $r): RedirectResponse
    {
        abort_unless($r->user()->role === 'ceo', 403);
        ExecutiveAccess::end($r);

        return to_route('executive.index')->with('status','Emergency override ended. Ordinary CEO oversight remains available.');
    }
}
