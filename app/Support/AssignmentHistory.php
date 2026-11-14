<?php

namespace App\Support;

use App\Models\Apprentice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AssignmentHistory
{
    public const KEYS = ['employer_id', 'tutor_id', 'tutor_group_id', 'mentor_id', 'careers_adviser_id', 'maths_tutor_id', 'english_tutor_id', 'programme_id'];

    public static function record(Apprentice $a, int $actor, ?array $before): void
    {
        $normalise = fn ($links) => array_map(fn ($id) => $id === null ? null : (int) $id, $links);
        $after = $normalise($a->only(self::KEYS));
        $before = $before === null ? null : $normalise($before);
        if ($before === $after) {
            return;
        }
        DB::table('assignment_change_events')->insert(['apprentice_id' => $a->id, 'actor_id' => $actor, 'before_links' => $before === null ? null : json_encode($before), 'after_links' => json_encode($after), 'created_at' => now()]);
    }

    public static function summary(Apprentice $a, Collection $events): array
    {
        $values = ['employer_id' => [], 'tutor_id' => []];
        foreach ($values as $key => $_) {
            if ($a->$key) {
                $values[$key][] = $a->$key;
            }
        }
        foreach ($events as $event) {
            foreach (['before_links', 'after_links'] as $side) {
                $data = json_decode($event->$side ?? 'null', true) ?? [];
                foreach ($values as $key => $_) {
                    if ($data[$key] ?? null) {
                        $values[$key][] = $data[$key];
                    }
                }
            }
        }

        return ['employers' => count(array_unique($values['employer_id'])) ?: null, 'tutors' => count(array_unique($values['tutor_id'])) ?: null, 'events' => $events->count(), 'complete_history' => false];
    }
}
