<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LocalTestOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(\App\Support\StagingAccess::requestAllowed($request), 503, 'Portal access is not enabled for this environment.');

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', $request->routeIs('employer-setup.*', 'employer-start.*', 'employer-accounts.*', 'acceptance.*', 'receipt-operations.*', 'destinations.*', 'workplace-support.*', 'apprentice-invitation.*', 'apprentice-onboarding.*', 'getting-started.*', 'onboarding.accept', 'onboarding.activate', 'account.email.confirm', 'account.email.verify', 'questionnaires.*', 'portal-questionnaires.*', 'electronic-reviews.*', 'review-authority.*', 'help.*', 'help-narrations.*', 'second-factor.*') ? 'no-referrer' : 'same-origin');

        return $response;
    }
}
