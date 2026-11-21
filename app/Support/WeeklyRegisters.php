<?php

namespace App\Support;

use App\Models\TrainingSession;
use App\Models\TutorGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WeeklyRegisters
{
    public static function next(TutorGroup $group, ?CarbonImmutable $from = null): ?array
    {
        $schedule = $group->weekly_register;
        if (!$group->active || !$schedule) {
            return null;
        }
        $from ??= CarbonImmutable::today(config('app.timezone'));
        $revisions = $schedule['revisions'];
        foreach ($revisions as $index => $settings) {
            $first = CarbonImmutable::parse($settings['first_day'], config('app.timezone'));
            $date = $first->greaterThan($from) ? $first : $from->addDays(($first->dayOfWeekIso - $from->dayOfWeekIso + 7) % 7);
            $day = $date->toDateString();
            $nextRevision = $revisions[$index + 1]['first_day'] ?? null;
            if (($nextRevision && $day >= $nextRevision) || (isset($settings['last_day']) && $day > $settings['last_day'])) {
                continue;
            }
            if (isset($schedule['closed_on']) && $day > $schedule['closed_on']) {
                return null;
            }

            return [
                'date' => $day,
                'week' => $date->startOfWeek(CarbonImmutable::MONDAY)->toDateString(),
                'settings' => $settings,
            ];
        }

        return null;
    }

    public static function open(User $actor, int $auth, TutorGroup $group, array $occurrence): TrainingSession
    {
        return DB::transaction(function () use ($actor, $auth, $group, $occurrence): TrainingSession {
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $group = TutorGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->auth_version === $auth && RegisterCreation::allowed($user, $group), 403);

            $next = self::next($group);
            if (!$next || $next['date'] !== $occurrence['date'] || $next['week'] !== $occurrence['week']) {
                throw ValidationException::withMessages(['weekly_register' => 'The weekly schedule changed. Reopen the group and check the next date.']);
            }
            $existing = TrainingSession::where('tutor_group_id', $group->id)->where('regular_week', $next['week'])->first();
            if ($existing) {
                return $existing;
            }

            $settings = $next['settings'];
            $session = RegisterCreation::store($user, $auth, $group, [
                'name' => 'Weekly register',
                'session_date' => $next['date'],
                'start_time' => $settings['start'],
                'end_time' => $settings['end'],
                'planned_learning' => $settings['planned_learning'],
            ], $settings['breaks']);
            $session->regular_week = $next['week'];
            $session->save();

            return $session;
        }, 3);
    }
}
