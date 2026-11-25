<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\AccountLifecycle;
use App\Support\CentreManagerAccess;
use App\Support\ExecutiveAccess;
use App\Support\LearningAccess;
use App\Support\SecondFactor;
use App\Support\SecurityAudit85;
use App\Support\SupportAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $identity = strtolower(trim($data['email']));
        $key = 'login:'.hash('sha256', $identity.'|'.$request->ip());
        $network = 'login85:ip:'.hash('sha256', (string) $request->ip());
        $account = 'login85:account:'.hash('sha256', $identity);
        if (RateLimiter::tooManyAttempts($key, 5) || RateLimiter::tooManyAttempts($network, 30)
            || RateLimiter::tooManyAttempts($account, 10)) {
            SecurityAudit85::record($request, 'login_throttled', [], $identity);
            throw ValidationException::withMessages(['email' => 'Too many attempts. Please try again later.']);
        }
        if (! Auth::validate([...$data, 'active' => true])) {
            RateLimiter::hit($network, 60);
            RateLimiter::hit($key, 60);
            RateLimiter::hit($account, 900);
            SecurityAudit85::record($request, 'login_failed', [], $identity);
            throw ValidationException::withMessages(['email' => 'The sign-in details were not recognised.']);
        }
        $attempted = Auth::guard()->getLastAttempted();

        return DB::transaction(function () use ($request, $attempted, $key, $account) {
            $user = User::whereKey($attempted->id)->lockForUpdate()->firstOrFail();
            if (! $user->active || AccountLifecycle::blocked($user) || ! hash_equals($user->password, $attempted->password) || $user->auth_version !== $attempted->auth_version) {
                throw ValidationException::withMessages(['email' => 'The sign-in details were not recognised.']);
            }
            RateLimiter::clear($key);
            RateLimiter::clear($account);
            if (SecondFactor::required($user)) {
                SecondFactor::begin($request, $user);

                return to_route('second-factor.challenge');
            }
            SecondFactor::complete($request, $user);

            return self::destination($request, $user);
        });
    }

    public static function destination(Request $request, User $user): RedirectResponse
    {
        $route = match ($user->role) {
            'admin', 'ceo', 'tutor' => 'attention.index', 'employer' => 'employer-actions.index','apprentice' => 'experience.home','manager','staff' => 'staff.index','mentor' => 'reviews.index','functional_skills_tutor' => 'em.caseload','careers_adviser' => 'careers.worklist', 'inclusion_lead','inclusion_member' => SupportAccess::team($user) ? 'support.worklist' : 'help.index',default => 'apprentices.index'
        };
        if (! LearningAccess::manager($user) && CentreManagerAccess::workspace($user)) {
            $route = 'centre-oversight.index';
        }
        // The public home historically pointed guests at the apprentice list.
        // Discard that one unavailable default for roles which cannot open that list.
        if (! in_array($user->role, ['admin', 'ceo', 'tutor', 'apprentice'], true) && $request->session()->get('url.intended') === route('apprentices.index')) {
            $request->session()->forget('url.intended');
        }

        return redirect()->intended(route($route));
    }

    public function destroy(Request $request): RedirectResponse
    {
        try {
            ExecutiveAccess::end($request);
        } finally {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return to_route('login');
    }
}
