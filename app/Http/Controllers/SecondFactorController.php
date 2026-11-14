<?php

namespace App\Http\Controllers;

use App\Models\AccountSecondFactor;
use App\Models\User;
use App\Support\SecondFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SecondFactorController extends Controller
{
    public function challenge(Request $request)
    {
        if (! SecondFactor::pending($request)) {
            $request->session()->forget('mfa_pending');
            return to_route('login');
        }
        return response()->view('auth.second-factor-challenge')->header('Cache-Control', 'no-store, private');
    }

    public function verify(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:64']]);
        $pending = SecondFactor::pending($request);
        SecondFactor::throttle($request, 'challenge', $pending['id'] ?? null);
        if (! $pending) { SecondFactor::fail(); }
        $user = DB::transaction(function () use ($pending, $data) {
            $user = User::whereKey($pending['id'])->lockForUpdate()->first();
            if (! $user || ! $user->active || $user->auth_version !== ($pending['version'] ?? null)
                || ! hash_equals(hash('sha256', $user->password), $pending['password_binding'] ?? '')) { SecondFactor::fail(); }
            $factor = AccountSecondFactor::whereKey($user->id)->lockForUpdate()->first();
            if (! $factor?->confirmed_at || ! SecondFactor::consume($factor, $data['code'])) { SecondFactor::fail(); }
            SecondFactor::event($user, 'mfa_sign_in');
            return $user;
        });
        SecondFactor::complete($request, $user);
        return SessionController::destination($request, $user);
    }

    public function cancel(Request $request)
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return to_route('login');
    }

    public function settings(Request $request)
    {
        $user = $request->user();
        $factor = AccountSecondFactor::find($user->id);
        abort_unless($factor?->confirmed_at || SecondFactor::mayEnrol($user), 403);
        $secret = null;
        if (! $factor?->confirmed_at && $factor?->setup_expires_at?->isFuture()
            && hash_equals($factor->setup_binding ?? '', hash('sha256', $request->session()->get('mfa_setup_binding', '')))) {
            try { $secret = Crypt::decryptString($factor->secret); } catch (\Throwable) { /* Restart setup; never expose a raw decrypt error. */ }
        }
        $shown = $request->session()->pull('mfa_recovery_codes');
        $codes = is_array($shown) && ($shown['expires'] ?? 0) > now()->timestamp ? $shown['codes'] : [];
        return response()->view('auth.second-factor-settings', ['factor' => $factor, 'secret' => $secret,
            'codes' => $codes, 'mayEnrol' => SecondFactor::mayEnrol($user)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function change(Request $request)
    {
        $data = $request->validate(['action' => ['required', Rule::in(['start', 'confirm', 'regenerate', 'disable'])],
            'current_password' => ['required', 'string', 'max:1024'],
            'code' => ['required_unless:action,start', 'nullable', 'string', 'max:64']]);
        SecondFactor::change($request, $data['action'], $data['current_password'], $data['code'] ?? '');
        return $data['action'] === 'disable' ? to_route('help.index')->with('status', 'Two-step sign-in disabled. Other sessions have been invalidated.')
            : to_route('second-factor.settings')->with('status', $data['action'] === 'start' ? 'Add the setup key to your authenticator, then confirm a code.' : 'Security settings updated. Save the new recovery codes now; they are shown once.');
    }
}
