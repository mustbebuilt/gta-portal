<?php

use App\Models\PortalPrivacyState;
use App\Models\User;
use App\Support\PortalPrivacy;
use App\Support\PrivacyRecovery85;
use App\Support\SecondFactor;
use App\Support\StagingAccess;
use App\Support\SupportAccess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

Artisan::command('gta:privacy-state-check', function () {
    $state = PortalPrivacyState::find(1);
    if (! $state) {
        $this->error('Privacy state is missing. Restore its verified backup; do not create a guessed state.');

        return 1;
    }
    $this->line('State metadata hash: '.PrivacyRecovery85::stateHash($state));
    $this->line('Revision: '.$state->revision.'; notice: '.($state->current_notice_id ?? 'none').'; enabled: '.($state->enabled ? 'yes' : 'no'));
    if (! PortalPrivacy::intact($state)) {
        $this->error('Proof cannot be verified. Preserve APP_KEY/previous keys and investigate before release.');

        return 1;
    }
    $this->info('Privacy state proof verified. This is not full deployment acceptance.');

    return 0;
})->purpose('Read-only privacy-state decrypt/integrity check');

Artisan::command('gta:privacy-state-reseal {--actor=} {--expected-state-hash=} {--reason=} {--apply}', function () {
    if (! $this->option('apply')) {
        $this->warn('No changes. Verify the original keys and state against a trusted backup/deployment record, then supply --actor, --expected-state-hash, --reason and --apply.');

        return 0;
    }
    try {
        PrivacyRecovery85::reseal((int) $this->option('actor'), (string) $this->option('expected-state-hash'), (string) $this->option('reason'));
    } catch (Throwable $e) {
        $this->error('Recovery refused. Check the actor, trusted state hash, reason, keys and published notice. Nothing committed.');

        return 1;
    }
    $this->info('Verified metadata resealed and recovery event recorded. No notice, acknowledgement or access decision changed.');

    return 0;
})->purpose('Explicit, audited recovery of a broken privacy-state proof');

Artisan::command('gta:audit85-check', function () {
    $failures = [];
    if (! Schema::hasTable('portal_security_events')) {
        $failures[] = 'Pack 85 security-event migration has not run.';
    }
    $demoCount = User::where('active', true)->where('email', 'like', '%@example.test')->count();
    if ($demoCount && app()->environment('production')) {
        $failures[] = $demoCount.' active example.test accounts need owner review/disablement before live acceptance.';
    }
    $state = PortalPrivacyState::find(1);
    if (! $state || ! PortalPrivacy::intact($state)) {
        $failures[] = 'Privacy state proof is missing or unreadable.';
    }
    if (app()->environment(['production', 'staging'])) {
        foreach (StagingAccess::errors() as $error) {
            $failures[] = $error;
        }
        if (config('second_factor.required_for_support') !== true) {
            $failures[] = 'Required two-factor policy is not activated. Complete verified staff enrolment/recovery checks, then enable GTA_MFA_REQUIRED_FOR_SUPPORT.';
        }
        $missing = User::where('active', true)->get()->filter(fn ($user) => (in_array($user->role, ['admin', 'ceo', 'inclusion_lead', 'inclusion_member'], true) || SupportAccess::team($user))
            && ! SecondFactor::required($user));
        if ($missing->count()) {
            $failures[] = $missing->count().' privileged/support accounts still need confirmed two-factor enrolment.';
        }
        if (app()->environment('local') || config('app.debug')) {
            $failures[] = 'Unsafe shared environment/debug configuration.';
        }
    }
    foreach ($failures as $failure) {
        $this->error($failure);
    }
    if ($failures) {
        return 1;
    }
    $this->info('Pack 85 source/configuration checks passed. Run the separate live/MySQL/phone acceptance checklist.');

    return 0;
})->purpose('Read-only audit recovery/deployment prerequisites; never repairs or disables accounts');
