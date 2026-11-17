<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\EmployerApprenticeGrant;
use App\Models\User;

class EmployerResourceAccess
{
    public function __invoke(?User $user = null): ?string
    {
        return self::resolve($user);
    }

    /** Resolve the authenticated user's authorised organisation scope key, or null to deny. */
    public static function resolve(?User $user): ?string
    {
        if (! $user) {
            return null;
        }
        $u = User::whereKey($user->id)->first();
        if (! $u || ! $u->active || AccountLifecycle::blocked($u)) {
            return null;
        }
        $request = request();
        $hasOwnSession = $request->hasSession() && (! $request->user() || (int) $request->user()->id === (int) $u->id);
        if ($hasOwnSession && $request->session()->has('auth_version') && (int) $request->session()->get('auth_version') !== (int) $u->auth_version) {
            return null;
        }
        $selected = $hasOwnSession
            ? ($request->session()->get('employer_resources65.employer_id') ?? $request->session()->get('selected_employer_id'))
            : null;

        if ($u->role === 'employer') {
            if ($u->email_verified_at === null) {
                return null;
            }
            $employerIds = [];
            foreach (EmployerApprenticeGrant::where('user_id', $u->id)->where('active', true)->orderBy('id')->get() as $g) {
                $a = Apprentice::find($g->apprentice_id);
                if ($a && EmployerActions::valid($g, $a) && ! in_array((int) $g->employer_id, $employerIds, true)) {
                    $employerIds[] = (int) $g->employer_id;
                }
            }
            if ($employerIds === []) {
                return null;
            }
            if ($selected !== null) {
                $selectedId = filter_var($selected, FILTER_VALIDATE_INT);

                return $selectedId !== false && in_array((int) $selectedId, $employerIds, true)
                    ? 'employer:'.((int) $selectedId)
                    : null;
            }

            return 'employer:'.$employerIds[0];
        }

        if (StaffDuties::has($u, 'mentor')) {
            if ($selected !== null) {
                $selectedId = filter_var($selected, FILTER_VALIDATE_INT);
                if ($selectedId === false) {
                    return null;
                }
                $assigned = Apprentice::where('mentor_id', $u->id)
                    ->where('employer_id', (int) $selectedId)
                    ->whereHas('employerRecord', fn ($q) => $q->where('active', true))
                    ->exists();

                return $assigned ? 'employer:'.((int) $selectedId) : null;
            }

            return 'gta-mentor';
        }

        if (LearningAccess::manager($u) || SupportAccess::team($u)) {
            return 'gta-staff-preview';
        }

        return null;
    }

    /** Check Gate access using the same configured organisation resolver as the controller. */
    public static function allowed(?User $user): bool
    {
        $resolver = config('employer_resources65.organisation_resolver');
        if (! is_callable($resolver)) {
            return false;
        }
        $organisation = $resolver($user);

        return is_scalar($organisation) && strlen((string) $organisation) > 0 && strlen((string) $organisation) <= 64;
    }
}
