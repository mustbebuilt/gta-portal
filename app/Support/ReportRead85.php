<?php

namespace App\Support;

use App\Models\Apprentice;
use Illuminate\Support\Collection;

/** Bounded to one read-only report invocation; never reused for writes or across requests. */
class ReportRead85
{
    private static ?array $frame = null;

    public static function run(Collection $people, callable $read): mixed
    {
        $previous = self::$frame;
        self::$frame = ['ids' => $people->pluck('id')->all(), 'people' => $people->keyBy('id'), 'tables' => [], 'memo' => []];
        try {
            return $read();
        } finally {
            self::$frame = $previous;
        }
    }

    public static function rows(string $model, int $id, callable $fallback): Collection
    {
        if (self::$frame === null || ! in_array($id, self::$frame['ids'], true)) {
            return $fallback();
        }
        if (! array_key_exists($model, self::$frame['tables'])) {
            self::$frame['tables'][$model] = $model::whereIn('apprentice_id', self::$frame['ids'])->orderBy('id')->get()->groupBy('apprentice_id');
        }

        return self::$frame['tables'][$model]->get($id, collect());
    }

    public static function programme(Apprentice $person): mixed
    {
        if (self::$frame !== null && isset(self::$frame['people'][$person->id])) {
            return self::$frame['people'][$person->id]->programmeRecord;
        }

        return $person->programmeRecord()->first();
    }

    public static function ids(): ?array
    {
        return self::$frame['ids'] ?? null;
    }

    public static function remember(string $key, callable $read): mixed
    {
        if (self::$frame === null) {
            return $read();
        }
        if (! array_key_exists($key, self::$frame['memo'])) {
            self::$frame['memo'][$key] = $read();
        }

        return self::$frame['memo'][$key];
    }
}
