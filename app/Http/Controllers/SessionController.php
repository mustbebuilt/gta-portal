<?php

namespace App\Http\Controllers;

use App\Support\ExecutiveAccess;
use App\Support\SupportAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        $key = 'login:'.hash('sha256', strtolower($data['email']).'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Too many attempts. Please try again in a minute.']);
        }
        if (! Auth::attempt([...$data, 'active' => true])) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'The sign-in details were not recognised.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put('auth_version', $request->user()->auth_version);

        return redirect()->intended(route(match ($request->user()->role) {
            'manager','staff' => 'staff.index','mentor' => 'reviews.index','functional_skills_tutor' => 'em.caseload','careers_adviser' => 'careers.worklist', 'inclusion_lead','inclusion_member' => SupportAccess::team($request->user()) ? 'support.worklist' : 'help.index',default => 'apprentices.index'
        }));
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
