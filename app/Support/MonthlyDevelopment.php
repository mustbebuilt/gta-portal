<?php

namespace App\Support;

use App\Models\{Apprentice, MonthlyScorecard, TrainingSession, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Storage component for the EXISTING monthly_scorecards table. */
final class MonthlyDevelopment
{
    public static function read(MonthlyScorecard $row): ?array
    {
        $value = $row->getRawOriginal('development');

        return $value === null ? null : json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }

    public static function save(User $actor, Apprentice $apprentice, TrainingSession $session, int $expected, array $ratings, string $comment): MonthlyScorecard
    {
        $content = ApprenticeReviewData::development($ratings, $comment);

        return DB::transaction(function () use ($actor, $apprentice, $session, $expected, $content) {
            // Same session -> apprentice lock order as AttendanceController. Enlist in its outer transaction.
            $s = TrainingSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            $a = Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();
            $u = User::findOrFail($actor->id);
            abort_unless($u->auth_version === $actor->auth_version && (LearningAccess::manager($u) || LearningAccess::tutor($u, $a)), 403);
            if ($s->state === 'Cancelled' || ! $s->ended() || ! in_array($a->id, $s->members, true) || ! in_array($s->marks()->where('apprentice_id', $a->id)->value('code'), ['O', 'T'], true)) {
                throw ValidationException::withMessages(['development' => 'Use an ended attended register containing this apprentice.']);
            }
            $month = $s->session_date->format('Y-m');
            if ($month > today()->format('Y-m') || ($a->start_date && $month < $a->start_date->format('Y-m'))) {
                throw ValidationException::withMessages(['development' => 'Check the session calendar month.']);
            }
            $row = MonthlyScorecard::where('apprentice_id', $a->id)->where('month', $month)->lockForUpdate()->first();
            if (($row?->version ?? 0) !== $expected) {
                throw ValidationException::withMessages(['development' => 'This monthly snapshot changed. Refresh before updating.']);
            }
            $before = $row ? self::read($row) : null;
            if ($before !== null && $before['schema'] === $content['schema'] && $before['ratings'] === $content['ratings'] && $before['comment'] === $content['comment']) {
                return $row;
            }
            $row ??= new MonthlyScorecard(['apprentice_id' => $a->id, 'month' => $month, 'tutor_id' => $u->id, 'scores' => []]);
            $content += ['by' => $u->id, 'at' => now()->toIso8601String(), 'session_id' => $s->id];
            $row->history = [...($row->history ?? []), ['event' => 'development_saved', 'before_development' => $before, 'by' => $u->id, 'at' => $content['at']]];
            $row->setAttribute('development', json_encode($content, JSON_THROW_ON_ERROR));
            if ($row->exists) {
                $row->version++;
            } else {
                $row->training_session_id = $s->id;
            }
            // Preserve all existing numeric scores, legacy comment, recorder and source register.
            $row->save();
            DB::table('learning_events')->insert(['user_id' => $u->id, 'apprentice_id' => $a->id, 'kind' => 'monthly_snapshot', 'record_id' => $row->id, 'event' => 'development_saved', 'created_at' => now()]);

            return $row;
        });
    }
}
