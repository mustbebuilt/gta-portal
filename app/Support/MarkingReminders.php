<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\LearningTask;
use App\Models\MarkingReminder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarkingReminders
{
    public static function allowed(User $user): bool
    {
        return $user->active && in_array($user->role, ['admin', 'ceo', 'tutor'], true);
    }

    public static function query(?User $user = null): Builder
    {
        $q = LearningTask::where('status', 'Awaiting marking');
        if ($user) {
            abort_unless(self::allowed($user), 403);
            if (! LearningAccess::manager($user)) {
                $q->whereHas('apprentice', fn ($a) => $a->where('tutor_id', $user->id));
            }
        }

        return $q;
    }

    public static function dateCheck(LearningTask $task): bool
    {
        return ! $task->submitted_at || $task->submitted_at->gt(now());
    }

    public static function source(LearningTask $task): ?array
    {
        $recipient = $task->apprentice->tutor;
        if (! $task->markingOverdue() || ! $recipient || ! $recipient->active || $recipient->role !== 'tutor') {
            return null;
        }
        $last = collect($task->submissions ?? [])->last();
        if (($task->template['kind'] ?? null) === 'source-pending' || ! is_array($last) || ! array_key_exists('decision', $last) || ($last['decision'] ?? null) !== null || ! is_int($last['version'] ?? null) || $last['version'] < 1 || ! is_string($last['submitted_at'] ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2}T/', $last['submitted_at'] ?? '') || ($last['template_hash'] ?? null) !== ($task->template['sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/', $last['template_hash'] ?? '')) {
            return null;
        }
        try {
            if (! Carbon::parse($last['submitted_at'])->equalTo($task->submitted_at)) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }
        $key = hash('sha256', json_encode([$task->id, $recipient->id, $last['version'], $task->submitted_at->toIso8601String(), $last['template_hash']], JSON_THROW_ON_ERROR));

        return ['source_key' => $key, 'recipient_id' => $recipient->id, 'submission_version' => $last['version'], 'submitted_at' => $task->submitted_at];
    }

    public static function generate(bool $record = false, ?User $actor = null): array
    {
        if ($actor) {
            abort_unless(LearningAccess::manager($actor), 403);
        }
        $run = function () use ($record, $actor): array {
            if ($record && $actor) {
                $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                abort_unless(LearningAccess::manager($actor), 403);
            }
            $counts = ['awaiting' => 0, 'overdue' => 0, 'eligible' => 0, 'already_recorded' => 0, 'created' => 0, 'date_checks' => 0, 'source_or_owner_checks' => 0];
            self::query()->select('learning_tasks.*')->chunkById(25, function ($tasks) use (&$counts, $record, $actor): void {
                foreach ($tasks as $task) {
                    if ($record) {
                        $a = Apprentice::whereKey($task->apprentice_id)->lockForUpdate()->firstOrFail();
                        $task = LearningTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
                        if ($task->status !== 'Awaiting marking') {
                            continue;
                        }
                        $task->setRelation('apprentice', $a);
                        $a->setRelation('tutor', $a->tutor_id ? User::whereKey($a->tutor_id)->lockForUpdate()->first() : null);
                    }
                    $counts['awaiting']++;
                    if (self::dateCheck($task)) {
                        $counts['date_checks']++;
                        continue;
                    }
                    if (! $task->markingOverdue()) {
                        continue;
                    }
                    $counts['overdue']++;
                    $source = self::source($task);
                    if (! $source) {
                        $counts['source_or_owner_checks']++;
                        continue;
                    }
                    $counts['eligible']++;
                    if (MarkingReminder::where('source_key', $source['source_key'])->exists()) {
                        $counts['already_recorded']++;
                        continue;
                    }
                    if ($record) {
                        $reminder = MarkingReminder::create(['learning_task_id' => $task->id, ...$source]);
                        self::event($reminder, $actor?->id, 'recorded', $actor ? 'administrator' : 'command');
                        $counts['created']++;
                    }
                }
            });

            return $counts;
        };

        return $record ? DB::transaction($run) : $run();
    }

    public static function seen(MarkingReminder $reminder, User $user): void
    {
        abort_unless(self::allowed($user) && $reminder->recipient_id === $user->id, 403);
        DB::transaction(function () use ($reminder, $user): void {
            $task = LearningTask::findOrFail($reminder->learning_task_id);
            $a = Apprentice::whereKey($task->apprentice_id)->lockForUpdate()->firstOrFail();
            $task = LearningTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            $current = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->active && $current->role === 'tutor' && $a->tutor_id === $current->id, 403);
            $task->setRelation('apprentice', $a);
            $a->setRelation('tutor', $current);
            $source = self::source($task);
            if (! $source || $source['source_key'] !== $reminder->source_key) {
                throw ValidationException::withMessages(['reminder' => 'This reminder is no longer current. Reopen the marking worklist.']);
            }
            $r = MarkingReminder::whereKey($reminder->id)->lockForUpdate()->firstOrFail();
            if (! $r->seen_at) {
                $r->seen_at = now();
                $r->save();
                self::event($r, $current->id, 'seen', 'recipient');
            }
        });
    }

    private static function event(MarkingReminder $reminder, ?int $user, string $event, string $origin): void
    {
        DB::table('marking_reminder_events')->insert(['marking_reminder_id' => $reminder->id, 'user_id' => $user, 'event' => $event, 'origin' => $origin, 'created_at' => now()]);
    }
}
