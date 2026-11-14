<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class MarkerHistory
{
    public static function codes(int $id): array
    {
        $v = DB::table('apprentice_markers')->where('apprentice_id', $id)->pluck('code')->all();
        sort($v, SORT_NATURAL);

        return $v;
    }

    public static function record(int $id, int $actor, string $source, array $before, ?string $reason = null): void
    {
        $after = self::codes($id);
        sort($before, SORT_NATURAL);
        if ($before === $after) {
            return;
        }DB::table('marker_change_events')->insert(['apprentice_id' => $id, 'actor_id' => $actor, 'source' => $source, 'before_codes' => json_encode($before), 'after_codes' => json_encode($after), 'reason' => $reason, 'created_at' => now()]);
    }
}
