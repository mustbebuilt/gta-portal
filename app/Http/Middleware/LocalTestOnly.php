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
        $response->headers->set('Referrer-Policy', $request->routeIs('centre-access.*','centre-oversight.*','centre-followups.*','historical-cohorts.*','optimiser.*','calendar.*','communications.*','review-workspace.*','restriction-recovery.*','work-moves.*','visual-reports.*','saved-work.*','quick-actions.*','record-checks.*','account-operations.*','cohort-insights.*','operations-desk.*','privacy.public','portal-privacy.*','portal-privacy-admin.*','occurrence-register.*','timetable-governance.*', 'timetable-management.*', 'timetable-duties.*', 'centre-planning.*', 'review-appointments.*', 'session-operations.*', 'session-changes.*', 'register-actions.*', 'cohort-setup.*', 'setup-cases.*', 'intakes.*', 'staff-handovers.*', 'incoming-handovers.*', 'employer-setup.*', 'employer-start.*', 'employer-accounts.*', 'acceptance.*', 'receipt-operations.*', 'destinations.*', 'workplace-support.*', 'apprentice-invitation.*', 'apprentice-onboarding.*', 'getting-started.*', 'onboarding.accept', 'onboarding.activate', 'account.email.confirm', 'account.email.verify', 'questionnaires.*', 'portal-questionnaires.*', 'electronic-reviews.*', 'review-authority.*', 'help.*', 'help-narrations.*', 'second-factor.*') ? 'no-referrer' : 'same-origin');

        return $response;
    }
}
