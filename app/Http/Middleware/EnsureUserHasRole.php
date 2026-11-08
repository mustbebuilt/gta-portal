<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $userRoleValue = $user->role->value ?? (string) $user->role;

        if (!in_array($userRoleValue, $roles, true)) {
            abort(Response::HTTP_FORBIDDEN, 'Access denied: You do not have the required role to view this resource.');
        }

        return $next($request);
    }
}

