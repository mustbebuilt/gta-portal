<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ? User::find($request->user()->id) : null;
        abort_unless($user?->active, 403);
        if ($request->session()->get('auth_version') !== $user->auth_version) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect()->route('login')->withErrors(['email' => 'Please sign in again. Your account access has changed.']);
        }
        Auth::setUser($user);

        return $next($request);
    }
}
