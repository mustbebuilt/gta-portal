<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\FormalKsbAssessment;

final class FormalKsbResults
{
    public const OUTCOMES = ['demonstrated'=>'Demonstrated', 'further_evidence'=>'Further evidence needed'];

    public static function key(?array $plan): ?string
    {
        $identity = $plan['identity'] ?? [];
        $mapping = $plan['details']['ksb_ref'] ?? null;
        if (!filled($mapping) || !filled($identity['programme_id'] ?? null) || !filled($identity['start'] ?? null)) {
            return null;
        }

        return ReviewedImports::hash([collect($identity)->only(['programme_id','code','version','start'])->all(), $mapping]);
    }

    public static function capture(Apprentice $apprentice, ?array $plan, ?string $through): ?array
    {
        if (!$key = self::key($plan)) {
            return null;
        }

        return FormalKsbAssessment::where('apprentice_id', $apprentice->id)->where('curriculum_key', $key)
            ->where('assessed_on', '<=', $through ?? today()->toDateString())
            ->orderByDesc('assessed_on')->orderByDesc('id')->get(['code','outcome','assessed_on'])
            ->unique('code')->sortBy('code', SORT_NATURAL)->values()->map(fn ($row) => [
                'code'=>$row->code, 'outcome'=>self::OUTCOMES[$row->outcome], 'date'=>$row->assessed_on->toDateString(),
            ])->all();
    }
}
