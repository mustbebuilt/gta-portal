<?php

namespace App\Http\Middleware;

use App\Support\SecondFactor;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RequireSecondFactor
{
    public function handle(Request $request, Closure $next)
    {
        if (SecondFactor::required($request->user()) && $request->session()->get('mfa_version') !== $request->user()->auth_version) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return to_route('login')->withErrors(['email' => 'Please sign in with your password and security code.']);
        }
        return $next($request);
    }
}
