<?php

namespace App\Support;

use App\Models\PortalPrivacyNotice;
use App\Models\PortalPrivacyState;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PrivacyRecovery85
{
    public static function stateHash(PortalPrivacyState $state): string
    {
        return ReviewedImports::hash(PortalPrivacy::fields($state));
    }

    public static function reseal(int $actorId, string $expected, string $reason): void
    {
        DB::transaction(function () use ($actorId, $expected, $reason): void {
            $actor = User::whereKey($actorId)->lockForUpdate()->firstOrFail();
            abort_unless($actor->active && in_array($actor->role, ['admin', 'ceo'], true), 403);
            $state = PortalPrivacyState::whereKey(1)->lockForUpdate()->firstOrFail();
            if (PortalPrivacy::intact($state) || ! hash_equals(self::stateHash($state), $expected)
                || mb_strlen(trim($reason)) < 10 || mb_strlen($reason) > 1000) {
                throw ValidationException::withMessages(['state' => 'Use a broken proof, a separately verified state hash and a recovery reason. Nothing changed.']);
            }
            // Never certify an enabled or selected notice whose immutable publication cannot be verified.
            if ($state->current_notice_id) {
                $notice = PortalPrivacyNotice::find($state->current_notice_id);
                if (! $notice || ! PortalPrivacy::published($notice)) {
                    throw ValidationException::withMessages(['state' => 'Recover the original encryption keys and verify the published notice first.']);
                }
            } elseif ($state->enabled) {
                throw ValidationException::withMessages(['state' => 'An enabled notice gate without a published notice cannot be resealed.']);
            }
            $before = PortalPrivacy::fields($state);
            $proofHash = hash('sha256', (string) $state->getRawOriginal('proof'));
            // Eloquent compares encrypted originals during save(), which cannot decrypt the broken proof.
            $after = [...$before, 'revision' => $state->revision + 1];
            DB::table('portal_privacy_state')->where('id', 1)->update([
                'revision' => $after['revision'],
                'proof' => Crypt::encryptString(json_encode($after, JSON_THROW_ON_ERROR)),
                'updated_at' => now(),
            ]);
            $state->refresh();
            PortalPrivacy::event($actor, 'state_resealed', 'state', [
                'before' => $before, 'after' => PortalPrivacy::fields($state), 'old_proof_hash' => $proofHash,
                'reason' => trim($reason), 'notice_or_acknowledgements_changed' => false,
            ]);
        });
    }
}
