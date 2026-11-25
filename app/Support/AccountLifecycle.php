<?php

namespace App\Support;

use App\Models\AccountLifecycleProposal as Proposal;
use App\Models\AccountLifecycleState as State;
use App\Models\Apprentice;
use App\Models\PortalPrivacyCase;
use App\Models\TutorGroup;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AccountLifecycle
{
    public const ACTIONS = ['Restrict', 'Archive', 'Restore'];

    public static function stateFields(State $s): array
    {
        return ['user_id' => $s->user_id, 'version' => $s->version, 'status' => $s->status, 'review_on' => $s->review_on?->toDateString()];
    }

    public static function stateIntact(State $s): bool
    {
        try {
            return $s->proof['fields'] === self::stateFields($s);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function blocked(User $u): bool
    {
        return ReportRead85::remember('lifecycle:'.$u->id, function () use ($u) {
            $s = State::find($u->id);

            return $s && (! self::stateIntact($s) || in_array($s->status, ['Restricted', 'Archived'], true));
        });
    }

    public static function proposalFields(Proposal $p): array
    {
        return $p->only(['id', 'request_token', 'target_id', 'created_by', 'action', 'source_hash']);
    }

    public static function intact(Proposal $p): bool
    {
        try {
            $d = $p->details;
            if ($d['fields'] !== self::proposalFields($p)) {
                return false;
            }if ($p->state === 'Draft') {
                return $p->version === 1 && ! $p->approval && ! $p->applied;
            }if ($p->state === 'Approved') {
                return $p->version === 2 && $p->approval['source_hash'] === $p->source_hash && ! $p->applied;
            }if ($p->state === 'Applied') {
                return $p->version === 3 && $p->applied['approval_hash'] === ReviewedImports::hash($p->approval) && $p->applied['source_hash'] === $p->source_hash;
            }if ($p->state === 'Rejected') {
                return $p->version === 2 && $p->approval['decision'] === 'Rejected' && ! $p->applied;
            }

return false;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function source(User $u, ?string $case = null): array
    {
        $fields = ['tutor_id', 'mentor_id', 'careers_adviser_id', 'maths_tutor_id', 'english_tutor_id', 'user_id'];
        $q = Apprentice::select(['id', ...$fields])->where(function ($q) use ($fields, $u) {
            foreach ($fields as $f) {
                $q->orWhere($f, $u->id);
            }
        })->orderBy('id')->get()->toArray();
        $groups = TutorGroup::where('tutor_id', $u->id)->orderBy('id')->get(['id', 'tutor_id', 'active'])->toArray();
        $state = State::find($u->id);
        $review = $case ? PortalPrivacyCase::findOrFail($case) : null;
        if ($review && ($review->user_id !== $u->id || ! PortalPrivacy::caseIntact($review) || ! in_array($review->state, ['Archive-review', 'Deletion-review'], true))) {
            CentrePlanning::fail('Choose this account\'s current retained privacy/account review.');
        }

        return ['hash' => ReviewedImports::hash(['user' => $u->getAttributes(), 'assignments' => $q, 'groups' => $groups, 'state' => $state?->getAttributes(), 'privacy_review' => $review?->getAttributes()]), 'assignments' => count($q), 'groups' => count($groups), 'target' => $u->only(['id', 'name', 'email', 'role', 'active', 'record_version', 'auth_version']), 'case' => $case];
    }

    public static function prepare(User $actor, int $auth, User $target, string $token, string $action, string $hash, string $reviewOn, string $retention, string $handover, string $reason, ?string $case = null): Proposal
    {
        return DB::transaction(function () use ($actor, $auth, $target, $token, $action, $hash, $reviewOn, $retention, $handover, $reason, $case): Proposal {
            User::orderBy('id')->lockForUpdate()->get(['id']);
            $u = PortalOperations::actor($actor, $auth, true);
            $t = User::findOrFail($target->id);
            abort_if($t->id === $u->id || $t->role === 'ceo', 403);
            if (! Str::isUuid($token) || ! in_array($action, self::ACTIONS, true) || ! PortalOperations::reference($reason) || ! PortalOperations::reference($retention)) {
                CentrePlanning::fail('Choose an account operation, a fresh request and reviewed retention/reason references.');
            }validator(['review_on' => $reviewOn], ['review_on' => 'required|date_format:Y-m-d|after:today'])->validate();
            $source = self::source($t, $case);
            if ($source['hash'] !== $hash) {
                CentrePlanning::fail('Account/assignment/review source changed; reload the preview.');
            }$input = ['review_on' => $reviewOn, 'retention' => $retention, 'handover' => $handover, 'reason' => $reason, 'case' => $case];
            $old = Proposal::where('request_token', $token)->first();
            if ($old) {
                if (self::intact($old) && $old->created_by === $u->id && $old->target_id === $t->id && $old->action === $action && $old->source_hash === $hash && $old->details['input'] === $input) {
                    return $old;
                }CentrePlanning::fail('Request token already used for a different account decision.');
            }
            if ($action === 'Restore' && $t->active && ! self::blocked($t) || $action !== 'Restore' && (! $t->active || self::blocked($t))) {
                CentrePlanning::fail('Choose an operation matching the current login state.');
            }if (($source['assignments'] || $source['groups'] || in_array($t->role, [...DirectoryCatalog::STAFF_ROLES, 'admin', 'ceo'], true)) && ! PortalOperations::reference($handover)) {
                CentrePlanning::fail('Record checked continuity/handover for existing assignments before changing login.');
            }
            $p = new Proposal(['id' => (string) Str::uuid(), 'request_token' => $token, 'target_id' => $t->id, 'created_by' => $u->id, 'action' => $action, 'state' => 'Draft', 'version' => 1, 'source_hash' => $hash]);
            $p->details = ['fields' => self::proposalFields($p), 'input' => $input, 'preview' => $source, 'prepared_auth' => $u->auth_version];
            $p->save();
            PortalOperations::event($u, 'account', $p->id, 'account_prepared', ['target' => $t->id, 'action' => $action, 'source_hash' => $hash]);

            return $p;
        }, 3);
    }

    public static function review(User $actor, int $auth, Proposal $record, int $version, bool $accept, string $reference, bool $override = false): void
    {
        DB::transaction(function () use ($actor, $auth, $record, $version, $accept, $reference, $override): void {
            User::orderBy('id')->lockForUpdate()->get(['id']);
            $u = PortalOperations::actor($actor, $auth, true);
            $p = Proposal::whereKey($record->id)->lockForUpdate()->firstOrFail();
            $t = User::findOrFail($p->target_id);
            $preparer = User::find($p->created_by);
            abort_if(($u->id === $p->created_by && ! ($u->role === 'ceo' && $override)) || ($override && $u->role !== 'ceo') || $u->id === $t->id || $t->role === 'ceo', 403);
            if (! self::intact($p) || $p->state !== 'Draft' || $p->version !== $version || ! PortalOperations::reference($reference) || ! $preparer || ! LearningAccess::manager($preparer) || self::blocked($preparer) || $preparer->auth_version !== $p->details['prepared_auth'] || self::source($t, $p->details['input']['case'])['hash'] !== $p->source_hash) {
                CentrePlanning::fail('Review the current intact account proposal and source independently.');
            }$p->version++;
            $p->state = $accept ? 'Approved' : 'Rejected';
            $p->approval = ['by' => $u->id, 'auth' => $u->auth_version, 'decision' => $p->state, 'ceo_override' => $override, 'source_hash' => $p->source_hash, 'reference' => $reference, 'at' => now()->toIso8601String()];
            $p->save();
            PortalOperations::event($u, 'account', $p->id, 'account_reviewed', ['approval' => $p->approval]);
        }, 3);
    }

    public static function apply(User $actor, int $auth, Proposal $record, int $version): void
    {
        DB::transaction(function () use ($actor, $auth, $record, $version): void {
            User::orderBy('id')->lockForUpdate()->get(['id']);
            $u = PortalOperations::actor($actor, $auth, true);
            $p = Proposal::whereKey($record->id)->lockForUpdate()->firstOrFail();
            $t = User::whereKey($p->target_id)->lockForUpdate()->firstOrFail();
            abort_if($u->id === $t->id || $t->role === 'ceo', 403);
            $maker = User::find($p->created_by);
            $checker = User::find($p->approval['by'] ?? 0);
            if (! self::intact($p) || $p->state !== 'Approved' || $p->version !== $version || ! $maker || ! $checker || $checker->id === $maker->id && ! ($checker->role === 'ceo' && ($p->approval['ceo_override'] ?? false)) || ! LearningAccess::manager($maker) || ! LearningAccess::manager($checker) || self::blocked($maker) || self::blocked($checker) || $maker->auth_version !== $p->details['prepared_auth'] || $checker->auth_version !== $p->approval['auth'] || self::source($t, $p->details['input']['case'])['hash'] !== $p->source_hash) {
                CentrePlanning::fail('Source or approving authority changed; prepare a fresh reviewed proposal.');
            }if ($p->action !== 'Restore' && in_array($t->role, ['admin', 'ceo'], true) && User::where('active', true)->whereIn('role', ['admin', 'ceo'])->where('id', '!=', $t->id)->get()->filter(fn ($x) => ! self::blocked($x))->isEmpty()) {
                CentrePlanning::fail('Preserve a privileged recovery operator.');
            }
            $before = $t->only(['id', 'role', 'active', 'record_version', 'auth_version']);
            $t->active = $p->action === 'Restore';
            if ($p->action !== 'Restore') {
                $t->password = Hash::make(Str::random(60));
            }AccountAccess::invalidate($t, $u->id, 'reviewed_'.strtolower($p->action), 'account_operations');
            DB::table('sessions')->where('user_id', $t->id)->delete();
            $s = State::whereKey($t->id)->lockForUpdate()->first();
            if ($s && ! self::stateIntact($s)) {
                CentrePlanning::fail('Managed account-state proof needs recovery.');
            }$s ??= new State(['user_id' => $t->id, 'version' => 0]);
            $history = $s->proof['history'] ?? [];
            $s->version++;
            $s->status = match ($p->action) {
                'Restore' => 'Active','Restrict' => 'Restricted',default => 'Archived'
            };
            $s->review_on = $p->details['input']['review_on'];
            $history[] = ['before' => $before, 'after' => $t->only(['id', 'role', 'active', 'record_version', 'auth_version']), 'proposal' => $p->id, 'by' => $u->id, 'at' => now()->toIso8601String()];
            $s->proof = ['fields' => self::stateFields($s), 'history' => $history, 'retention_reference' => $p->details['input']['retention'], 'handover_reference' => $p->details['input']['handover']];
            $s->save();
            $p->state = 'Applied';
            $p->version++;
            $p->applied = ['source_hash' => $p->source_hash, 'approval_hash' => ReviewedImports::hash($p->approval), 'by' => $u->id, 'at' => now()->toIso8601String(), 'state_version' => $s->version];
            $p->save();
            PortalOperations::event($u, 'account', $p->id, 'account_applied', ['before' => $before, 'after' => $history[array_key_last($history)]['after'], 'state' => self::stateFields($s), 'evidence_deleted' => false]);
            if ($case = $p->details['input']['case']) {
                PortalPrivacy::event($u, 'account_review_action_recorded', $case, ['proposal' => $p->id, 'action' => $p->action, 'concern_not_automatically_resolved' => true]);
            }
        }, 3);
    }

    public static function retain(User $actor, int $auth, User $target, int $version, string $reviewOn, string $reference, string $reason): void
    {
        DB::transaction(function () use ($actor, $auth, $target, $version, $reviewOn, $reference, $reason): void {
            User::whereIn('id', [$actor->id, $target->id])->orderBy('id')->lockForUpdate()->get();
            $u = PortalOperations::actor($actor, $auth, true);
            $s = State::whereKey($target->id)->lockForUpdate()->firstOrFail();
            if (! self::stateIntact($s) || $s->version !== $version || ! PortalOperations::reference($reference) || ! PortalOperations::reference($reason)) {
                CentrePlanning::fail('Review the current managed-account retention reference and version.');
            }validator(['review_on' => $reviewOn], ['review_on' => 'required|date_format:Y-m-d|after:today'])->validate();
            $before = self::stateFields($s);
            $proof = $s->proof;
            $s->version++;
            $s->review_on = $reviewOn;
            $proof['fields'] = self::stateFields($s);
            $proof['retention_reference'] = $reference;
            $proof['retention_reviews'][] = ['by' => $u->id, 'before' => $before, 'reference' => $reference, 'reason' => $reason, 'at' => now()->toIso8601String()];
            $s->proof = $proof;
            $s->save();
            PortalOperations::event($u, 'account', (string) $target->id, 'retention_reviewed', ['before' => $before, 'after' => self::stateFields($s), 'reference' => $reference, 'reason' => $reason, 'login_changed' => false, 'records_deleted' => false]);
        }, 3);
    }

    public static function ledger(User $u): string
    {
        abort_unless(LearningAccess::manager($u) && ! self::blocked($u), 403);
        $rows = [];
        foreach (State::whereIn('status', ['Restricted', 'Archived'])->orderBy('user_id')->get() as $s) {
            if (! self::stateIntact($s)) {
                CentrePlanning::fail('Repair account proof before exporting restriction evidence.');
            }$t = User::findOrFail($s->user_id);
            $rows[] = ['user_id' => $t->id, 'identity_hash' => ReviewedImports::hash([$t->email, $t->created_at?->toIso8601String()]), 'status' => $s->status, 'version' => $s->version];
        }

return Crypt::encryptString(json_encode(['format' => 'gta-account-restrictions-43', 'created_at' => now()->toIso8601String(), 'rows' => $rows], JSON_THROW_ON_ERROR));
    }

    public static function inspectLedger(User $u, string $sealed): array
    {
        abort_unless(LearningAccess::manager($u) && ! self::blocked($u), 403);
        try {
            $v = json_decode(Crypt::decryptString($sealed), true, 32, JSON_THROW_ON_ERROR);
            if (($v['format'] ?? null) !== 'gta-account-restrictions-43' || ! is_array($v['rows'] ?? null) || count($v['rows']) > 5000) {
                throw new \RuntimeException;
            }$out = [];
            foreach ($v['rows'] as $r) {
                $t = User::find($r['user_id']);
                if (! in_array($r['status'], ['Restricted', 'Archived'], true)) {
                    throw new \RuntimeException;
                }$identity = $t && hash_equals($r['identity_hash'],ReviewedImports::hash([$t->email, $t->created_at?->toIso8601String()]));
                $out[] = ['user_id' => $r['user_id'], 'identity_matches' => (bool) $identity, 'recorded_restriction' => $r['status'], 'login_still_blocked' => $identity && (! $t->active || self::blocked($t)), 'action' => $identity ? 'Review current source and use independently approved account operation if needed' : 'Identity needs manual recovery; do not match by name'];
            }

return ['captured_at' => $v['created_at'], 'rows' => $out, 'writes_performed' => false, 'automatic_reactivation' => false];
        } catch (\Throwable) {
            CentrePlanning::fail('Restriction evidence could not be authenticated with the retained key/context.');
        }
    }
}
