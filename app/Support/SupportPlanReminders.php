<?php

namespace App\Support;

use App\Models\{Apprentice, SupportPlan, SupportPlanReminder, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupportPlanReminders
{
    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) { return null; }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    private static function timestamp(mixed $value): bool
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) { return false; }
        try {
            $date = new \DateTimeImmutable($value);
            return $date->format('Y-m-d\TH:i:s') === substr($value, 0, 19) && $date->getTimestamp() <= now()->timestamp;
        } catch (\Throwable) { return false; }
    }

    public static function position(SupportPlan $plan): array
    {
        $none = fn ($state) => ['state' => $state, 'source' => null, 'key' => null, 'due' => null];
        if ($plan->status !== 'Active') { return $none('inactive'); }
        $active = $plan->active_plan;
        if (! is_array($active) || ! self::timestamp($active['approved_at'] ?? null)) { return $none('source_checks'); }
        $approved = substr($active['approved_at'], 0, 10);
        $due = self::date($active['fields']['review'] ?? null);
        if (! $due || $due < $approved) { return $none('source_checks'); }
        $activeHash = ReviewedImports::hash($active);$last = null;$lastDate = $approved;
        foreach ($plan->reviews ?? [] as $review) {
            if (! is_array($review)) { return $none('source_checks'); }
            if (($review['active_snapshot']['approved_at'] ?? null) !== $active['approved_at']) { continue; }
            $date = self::date($review['date'] ?? null);$next = self::date($review['next'] ?? null);
            if (! is_array($review['active_snapshot'] ?? null) || ReviewedImports::hash($review['active_snapshot']) !== $activeHash
                || ! $date || $date < $lastDate || $date > today()->toDateString() || ! $next || $next < $date) { return $none('source_checks'); }
            $last = $review;$lastDate = $date;$due = $next;
        }
        if ($plan->nextReview() !== $due) { return $none('source_checks'); }
        if ($due > today()->toDateString()) { return ['state' => 'upcoming', 'source' => null, 'key' => null, 'due' => $due]; }
        $a = Apprentice::find($plan->apprentice_id);
        $owner = $plan->review_owner_id ? User::find($plan->review_owner_id) : null;
        if (! $a || ! $owner || ! SupportAccess::writer($owner, $a) || $plan->review_owner_revision < 1) {
            return ['state' => 'coordinator_checks', 'source' => null, 'key' => null, 'due' => $due];
        }
        $source = ['plan_id' => $plan->id, 'apprentice_id' => $a->id, 'recipient_id' => $owner->id,
            'owner_revision' => $plan->review_owner_revision, 'owner_auth_version' => $owner->auth_version,
            'access_hash' => ReviewedImports::hash(SupportAccess::settings($owner)), 'active_hash' => $activeHash,
            'review_hash' => $last ? ReviewedImports::hash($last) : null, 'due' => $due];
        return ['state' => 'due', 'source' => $source, 'key' => ReviewedImports::hash($source), 'due' => $due];
    }

    public static function current(SupportPlanReminder $reminder, User $user): bool
    {
        $plan = SupportPlan::find($reminder->support_plan_id);
        $p = $plan ? self::position($plan) : null;
        return $reminder->recipient_id === $user->id && is_array($reminder->source)
            && ReviewedImports::hash($reminder->source) === $reminder->source_key
            && ($p['source']['recipient_id'] ?? null) === $user->id && ($p['key'] ?? null) === $reminder->source_key;
    }

    public static function assign(SupportPlan $plan, User $actor, int $authVersion, int $version, ?int $ownerId): void
    {
        DB::transaction(function () use ($plan, $actor, $authVersion, $version, $ownerId) {
            $p = SupportPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $a = Apprentice::whereKey($p->apprentice_id)->lockForUpdate()->firstOrFail();
            $u = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($u->auth_version === $authVersion && SupportAccess::lead($u, $a), 403);
            if ($p->status !== 'Active' || ! $p->active_plan || $p->version !== $version) {
                throw ValidationException::withMessages(['coordinator' => 'Open the current active plan before changing its coordinator.']);
            }
            $owner = $ownerId ? User::whereKey($ownerId)->lockForUpdate()->first() : null;
            if ($ownerId && (! $owner || ! SupportAccess::writer($owner, $a))) {
                throw ValidationException::withMessages(['coordinator' => 'Choose an active Inclusion Team member with access to this apprentice.']);
            }
            if ($p->review_owner_id === $ownerId) { return; }
            $history = $p->history ?? [];
            $history[] = ['event' => 'review_coordinator_changed', 'before' => $p->review_owner_id, 'after' => $ownerId,
                'by' => $u->id, 'at' => now()->toIso8601String()];
            $p->review_owner_id = $ownerId;$p->review_owner_revision++;$p->version++;$p->history = $history;$p->save();
        });
    }

    private static function event(SupportPlanReminder $row, ?int $actor, string $event): void
    {
        DB::table('support_plan_reminder_events')->insert(['reminder_id' => $row->id, 'actor_id' => $actor,
            'event' => $event, 'created_at' => now()]);
    }

    public static function generate(bool $record = false): array
    {
        $run = function () use ($record) {
            $counts = ['plans' => 0, 'due' => 0, 'upcoming' => 0, 'inactive' => 0, 'source_checks' => 0,
                'coordinator_checks' => 0, 'existing' => 0, 'created' => 0];
            SupportPlan::select(['id','apprentice_id','status','active_plan','reviews','review_owner_id','review_owner_revision'])->orderBy('id')->chunkById(50, function ($plans) use ($record, &$counts) {
                foreach ($plans as $plan) {
                    if ($record) { $plan = SupportPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail(); }
                    $p = self::position($plan);$counts['plans']++;$counts[$p['state']]++;
                    if (! $p['source']) { continue; }
                    $existing = SupportPlanReminder::where('source_key', $p['key'])->first();
                    if ($existing) { $counts['existing']++;continue; }
                    if ($record) {
                        $row = SupportPlanReminder::create(['support_plan_id' => $plan->id, 'recipient_id' => $p['source']['recipient_id'],
                            'source' => $p['source'], 'source_key' => $p['key']]);
                        self::event($row, null, 'recorded');$counts['created']++;
                    }
                }
            });
            return $counts;
        };
        return $record ? DB::transaction($run) : $run();
    }

    public static function seen(SupportPlanReminder $reminder, User $actor, int $authVersion): void
    {
        self::acknowledge($reminder, $actor, $authVersion, false);
    }

    public static function acknowledge(SupportPlanReminder $reminder, User $actor, int $authVersion, bool $dismiss): void
    {
        DB::transaction(function () use ($reminder, $actor, $authVersion, $dismiss) {
            $row = SupportPlanReminder::whereKey($reminder->id)->lockForUpdate()->firstOrFail();
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $plan = SupportPlan::findOrFail($row->support_plan_id);
            abort_unless($user->active && $user->auth_version === $authVersion && $row->recipient_id === $user->id
                && SupportAccess::team($user, $plan->apprentice), 403);
            abort_unless($dismiss ? ! self::current($row, $user) : self::current($row, $user), 403);
            if ($row->seen_at || $row->dismissed_at) { return; }
            $column = $dismiss ? 'dismissed_at' : 'seen_at';$row->{$column} = now();$row->save();
            self::event($row, $user->id, $dismiss ? 'dismissed_outdated' : 'seen');
        });
    }
}
