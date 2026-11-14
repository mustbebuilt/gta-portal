<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\Employer;
use App\Models\EmployerApprenticeGrant;
use App\Models\EmployerPortalAction;
use App\Models\ExtraTraining;
use App\Models\LearningTask;
use App\Models\ProgressReviewDraft;
use App\Models\SmartAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployerActions
{
    public const KINDS = ['review' => 'Review content response', 'workplace' => 'Workplace training confirmation', 'target' => 'Workplace target response'];

    public const RESPONSES = ['Confirmed', 'Changes requested', 'Cannot confirm'];

    public static function staff(User $u, Apprentice $a): bool
    {
        return LearningAccess::manager($u) || LearningAccess::tutor($u, $a);
    }

    public static function identity(User $u): string
    {
        return ReviewedImports::hash($u->only('id', 'name', 'email'));
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['actions' => $message]);
    }

    public static function valid(EmployerApprenticeGrant $g, Apprentice $a): bool
    {
        $u = User::whereKey($g->user_id)->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->first();
        $e = Employer::whereKey($g->employer_id)->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->first();

        return $g->apprentice_id === $a->id && $g->active && $a->employer_id === $g->employer_id && $e?->active && $e->record_version === $g->employer_version && $u?->active && $u->role === 'employer' && $u->email_verified_at && hash_equals($g->identity_hash, self::identity($u)) && User::whereRaw('LOWER(email) = ?', [strtolower($u->email)])->count() === 1;
    }

    public static function actor(User $actor, int $authVersion, Apprentice $a, bool $management = false): User
    {
        $u = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        abort_unless($u->auth_version === $authVersion && ($management ? LearningAccess::manager($u) : self::staff($u, $a)), 403);

        return $u;
    }

    public static function grant(User $actor, int $authVersion, Apprentice $profile, array $data): EmployerApprenticeGrant
    {
        return DB::transaction(function () use ($actor, $authVersion, $profile, $data) {
            $a = Apprentice::whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $u = self::actor($actor, $authVersion, $a, true);
            $target = User::whereKey($data['user_id'])->lockForUpdate()->firstOrFail();
            $e = $a->employer_id ? Employer::whereKey($a->employer_id)->lockForUpdate()->first() : null;
            if (! $e?->active || ! $target->active || $target->role !== 'employer' || ! $target->email_verified_at || User::whereRaw('LOWER(email) = ?', [strtolower($target->email)])->count() !== 1) {
                self::fail('Use a current active linked employer and one verified employer login. Mailbox verification alone does not establish workplace authority.');
            }
            $g = EmployerApprenticeGrant::where('apprentice_id', $a->id)->where('user_id', $target->id)->lockForUpdate()->first();
            if (($g?->version ?? 0) !== (int) $data['version']) {
                self::fail('Employer access changed. Reload before verifying.');
            }
            $g ??= new EmployerApprenticeGrant(['apprentice_id' => $a->id, 'user_id' => $target->id]);
            $history = $g->history ?? [];
            $history[] = ['event' => 'identity_and_authority_verified', 'by' => $u->id, 'at' => now()->toIso8601String(), 'reference' => $data['identity_reference']];
            $g->fill(['employer_id' => $e->id, 'identity_hash' => self::identity($target), 'employer_version' => $e->record_version, 'identity_reference' => $data['identity_reference'], 'verified_by' => $u->id, 'active' => true, 'version' => ($g->exists ? $g->version : 0) + 1, 'history' => $history]);
            $g->save();
            self::audit($u, $a, 'employer_access_verified');

            return $g;
        });
    }

    public static function revoke(User $actor, int $authVersion, EmployerApprenticeGrant $grant, int $version, string $reason): void
    {
        DB::transaction(function () use ($actor, $authVersion, $grant, $version, $reason): void {
            $a = Apprentice::whereKey($grant->apprentice_id)->lockForUpdate()->firstOrFail();
            $u = self::actor($actor, $authVersion, $a, true);
            $g = EmployerApprenticeGrant::whereKey($grant->id)->lockForUpdate()->firstOrFail();
            if ($g->version !== $version || ! $g->active) {
                self::fail('Grant changed or is already revoked.');
            }$g->active = false;
            $g->version++;
            $g->history = [...$g->history, ['event' => 'revoked', 'by' => $u->id, 'at' => now()->toIso8601String(), 'reason' => $reason]];
            $g->save();
            self::audit($u, $a, 'employer_access_revoked');
        });
    }

    public static function source(Apprentice $a, EmployerApprenticeGrant $g, string $kind, int $id, array $public, bool $lock = false): array
    {
        if (! self::valid($g, $a)) {
            self::fail('Employer authority needs rechecking.');
        }
        $class = match ($kind) {
            'review' => ProgressReviewDraft::class,'workplace' => ExtraTraining::class,'target' => SmartAction::class,default => null
        };
        if (! $class) {
            self::fail('Unknown employer action kind.');
        }
        $record = $class::whereKey($id)->where('apprentice_id', $a->id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        if (! $record || ($kind === 'review' && $record->state !== 'Ready for signatures') || ($kind === 'workplace' && ($record->withdrawn_at || ! $record->occurred())) || ($kind === 'target' && ($record->owner !== 'Employer / workplace mentor' || $record->status === 'Completed' || ($record->learning_task_id && ! LearningTask::whereKey($record->learning_task_id)->where('apprentice_id', $a->id)->exists())))) {
            self::fail('Choose this apprentice’s frozen review, occurred/unwithdrawn training record or outstanding employer-owned target.');
        }

        $linked = $kind === 'target' && $record->learning_task_id ? LearningTask::whereKey($record->learning_task_id)->where('apprentice_id', $a->id)->when($lock, fn ($q) => $q->lockForUpdate())->first() : null;
        if ($linked && $linked->status === 'Approved') {
            self::fail('This linked target is already completed by task approval.');
        }

        return ['linked_task_hash' => $linked ? ReviewedImports::hash($linked->getAttributes()) : null, 'profile' => ['id' => $a->id, 'employer_id' => $a->employer_id], 'grant' => ['id' => $g->id, 'version' => $g->version, 'identity_hash' => $g->identity_hash], 'kind' => $kind, 'source_id' => $id, 'record_version' => $record->version, 'record_hash' => ReviewedImports::hash($record->getAttributes()), 'publication' => $public];
    }

    public static function staffSourceUrl(EmployerPortalAction $x): string
    {
        return match ($x->kind) {
            'review' => route('reviews.edit', [$x->apprentice_id, $x->source_id]),'workplace' => route('extra.index', $x->apprentice_id),default => route('actions.index', $x->apprentice_id).'#action-'.$x->source_id
        };
    }

    public static function grantBinding(EmployerPortalAction $x, EmployerApprenticeGrant $g): bool
    {
        return ($x->source['grant']['id'] ?? null) === $g->id && ($x->source['grant']['version'] ?? null) === $g->version && ($x->source['grant']['identity_hash'] ?? null) === $g->identity_hash && ($x->source['profile']['id'] ?? null) === $g->apprentice_id && ($x->source['profile']['employer_id'] ?? null) === $g->employer_id && ReviewedImports::hash($x->source) === $x->source_hash;
    }

    public static function current(EmployerPortalAction $action, Apprentice $a, EmployerApprenticeGrant $g): bool
    {
        try {
            return ReviewedImports::hash($action->source) === $action->source_hash && ReviewedImports::hash(self::source($a, $g, $action->kind, $action->source_id, ['title' => $action->title, 'detail' => $action->detail, 'due_date' => $action->due_date?->toDateString()], DB::transactionLevel() > 0)) === $action->source_hash;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function publish(User $actor, int $authVersion, Apprentice $profile, array $data): EmployerPortalAction
    {
        return DB::transaction(function () use ($actor, $authVersion, $profile, $data) {
            $a = Apprentice::whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $u = self::actor($actor, $authVersion, $a);
            $old = EmployerPortalAction::whereKey($data['request_token'])->first();
            if ($old) {
                abort_unless($old->apprentice_id === $a->id && $old->created_by === $u->id, 409);

                return $old;
            }
            $g = EmployerApprenticeGrant::whereKey($data['grant_id'])->where('apprentice_id', $a->id)->lockForUpdate()->firstOrFail();
            $public = collect($data)->only('title', 'detail', 'due_date')->all();
            $public['due_date'] ??= null;
            $source = self::source($a, $g, $data['kind'], (int) $data['source_id'], $public, true);
            if (EmployerPortalAction::where('employer_apprentice_grant_id', $g->id)->where('kind', $data['kind'])->where('source_id', $data['source_id'])->where('state', 'Pending')->where('source_hash', ReviewedImports::hash($source))->exists()) {
                self::fail('The same published request is already pending.');
            }
            $x = EmployerPortalAction::create(['id' => $data['request_token'], 'apprentice_id' => $a->id, 'employer_apprentice_grant_id' => $g->id, 'created_by' => $u->id, 'kind' => $data['kind'], 'source_id' => $data['source_id'], 'source' => $source, 'source_hash' => ReviewedImports::hash($source), ...$public, 'state' => 'Pending', 'version' => 1, 'history' => [['event' => 'published', 'by' => $u->id, 'at' => now()->toIso8601String()]]]);
            self::audit($u, $a, 'employer_action_published');

            return $x;
        });
    }

    public static function respond(User $actor, int $authVersion, EmployerPortalAction $action, array $data): void
    {
        DB::transaction(function () use ($actor, $authVersion, $action, $data): void {
            $a = Apprentice::whereKey($action->apprentice_id)->lockForUpdate()->firstOrFail();
            $u = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $g = EmployerApprenticeGrant::whereKey($action->employer_apprentice_grant_id)->lockForUpdate()->firstOrFail();
            abort_unless($u->auth_version === $authVersion && $g->user_id === $u->id && $u->role === 'employer' && self::valid($g, $a), 403);
            $x = EmployerPortalAction::whereKey($action->id)->lockForUpdate()->firstOrFail();
            if ($x->version !== (int) $data['version'] || $x->state !== 'Pending' || ! self::current($x, $a, $g)) {
                self::fail('Request changed, was answered/withdrawn, or its source/authority needs rechecking. Ask GTA for a fresh request.');
            }
            $x->state = 'Responded';
            $x->response = $data['response'];
            $x->response_note = $data['note'];
            $x->responded_by = $u->id;
            $x->responded_at = now();
            $x->version++;
            $x->history = [...$x->history, ['event' => 'employer_responded', 'by' => $u->id, 'at' => now()->toIso8601String(), 'response' => $x->response, 'note' => $x->response_note]];
            $x->save();
            self::audit($u, $a, 'employer_action_responded');
        });
    }

    public static function staffChange(User $actor, int $authVersion, EmployerPortalAction $action, array $data): void
    {
        DB::transaction(function () use ($actor, $authVersion, $action, $data): void {
            $a = Apprentice::whereKey($action->apprentice_id)->lockForUpdate()->firstOrFail();
            $u = self::actor($actor, $authVersion, $a);
            $x = EmployerPortalAction::whereKey($action->id)->lockForUpdate()->firstOrFail();
            if ($x->version !== (int) $data['version'] || ! in_array($x->state, ['Pending', 'Responded'], true) || ($data['state'] === 'Checked' && $x->state !== 'Responded')) {
                self::fail('Only a current reply can be checked, or an open request withdrawn.');
            }
            $x->state = $data['state'];
            $x->version++;
            $x->history = [...$x->history, ['event' => $x->state, 'by' => $u->id, 'at' => now()->toIso8601String(), 'note' => $data['note']]];
            $x->save();
            self::audit($u, $a, 'employer_action_staff_checked');
        });
    }

    public static function audit(User $u, Apprentice $a, string $event): void
    {
        DB::table('audit_events')->insert(['user_id' => $u->id, 'apprentice_id' => $a->id, 'event' => $event, 'created_at' => now()]);
    }
}
