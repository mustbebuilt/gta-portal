<?php

namespace App\Http\Middleware;

use App\Support\StagingAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LocalTestOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(StagingAccess::requestAllowed($request), 503, 'Portal access is not enabled for this environment.');

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', $request->routeIs('progress66.*', 'employer-resources.*', 'experience.home', 'gateway.*', 'journey.*', 'annual.*', 'centre-access.*', 'centre-oversight.*', 'centre-followups.*', 'historical-cohorts.*', 'optimiser.*', 'calendar.*', 'communications.*', 'review-workspace.*', 'restriction-recovery.*', 'work-moves.*', 'visual-reports.*', 'saved-work.*', 'quick-actions.*', 'record-checks.*', 'account-operations.*', 'cohort-insights.*', 'operations-desk.*', 'privacy.public', 'portal-privacy.*', 'portal-privacy-admin.*', 'occurrence-register.*', 'timetable-governance.*', 'timetable-management.*', 'timetable-duties.*', 'centre-planning.*', 'review-appointments.*', 'session-operations.*', 'session-changes.*', 'register-actions.*', 'cohort-setup.*', 'setup-cases.*', 'intakes.*', 'staff-handovers.*', 'incoming-handovers.*', 'employer-setup.*', 'employer-start.*', 'employer-accounts.*', 'acceptance.*', 'receipt-operations.*', 'destinations.*', 'workplace-support.*', 'apprentice-invitation.*', 'apprentice-onboarding.*', 'getting-started.*', 'onboarding.accept', 'onboarding.activate', 'account.email.confirm', 'account.email.verify', 'questionnaires.*', 'portal-questionnaires.*', 'electronic-reviews.*', 'review-authority.*', 'help.*', 'help-narrations.*', 'second-factor.*') ? 'no-referrer' : 'same-origin');

        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()');
        if ($request->isSecure() && app()->environment(['staging', 'production'])) {
            $response->headers->set('Strict-Transport-Security', 'max-age=15552000');
        }
        // Observe first: enforce CSP only after checking the existing scripts/assets on the actual host.
        $response->headers->set('Content-Security-Policy-Report-Only', "default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");

        return $response;
    }
}
