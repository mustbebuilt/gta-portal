<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\IndividualDeliveryPlan;
use App\Models\LearningTask;
use Carbon\CarbonImmutable;

class DeliveryPlan
{
    public static function identity(Apprentice $a): array
    {
        $p = $a->programmeRecord()->first();

        return ['programme_id' => $a->programme_id, 'catalogue' => TaskCatalogue::keyFor($a), 'code' => $p?->standard_code, 'version' => $p?->version, 'active' => $p?->active, 'start' => $a->start_date?->toDateString(), 'employer_id' => $a->employer_id, 'employer' => $a->employerLabel()];
    }

    public static function catalogueHash(string $key): string
    {
        return hash('sha256', file_get_contents(resource_path('data/'.$key.'-tasks.json')).file_get_contents(resource_path('data/'.$key.'-catalogue.json')));
    }

    public static function approved(Apprentice $a): ?array
    {
        $row = IndividualDeliveryPlan::where('apprentice_id', $a->id)->first();
        if (! $row?->active || ! $row->approved || ($row->approved['identity'] ?? null) !== self::identity($a)) {
            return null;
        }

        if ($row->approved['rpl_binding'] ?? null) {
            $w = \App\Models\TrainingPlanWorkspace::where('apprentice_id', $a->id)->first();
            $p = TrainingPlanSource::position($w);
            if (! $p['approved'] || $p['binding'] !== $row->approved['rpl_binding']) { return null; }
        }

        // A later blank-task release does not rewrite an already approved individual plan.
        return $row->approved;
    }

    public static function blocks(Apprentice $a): array
    {
        $key = TaskCatalogue::keyFor($a);
        $tasks = $key ? collect(TaskCatalogue::tasks($key)) : collect();

        return collect(range(1, 12))->map(fn ($n) => ['number' => $n, 'title' => $n <= 10 ? ($tasks->firstWhere('block', $n)['theme'] ?? 'Source not loaded') : ($n === 11 ? 'Gateway readiness - content approval separate' : 'EPA window - assessment, no training hours'), 'start' => null, 'end' => null, 'hours' => $n === 12 ? '0' : null])->all();
    }

    public static function taskBinding(Apprentice $a): string
    {
        return hash('sha256', json_encode(LearningTask::where('apprentice_id', $a->id)->orderBy('id')->get(['id', 'reference', 'version', 'status', 'due_date', 'template'])->toArray(), JSON_THROW_ON_ERROR));
    }

    public static function deadline(LearningTask $t, array $plan): ?string
    {
        if (! in_array($t->status, ['Draft', 'Referred'], true) || $t->due_date || TaskCatalogue::savedKey($t) !== $plan['identity']['catalogue'] || ($t->template['kind'] ?? null) === 'source-pending' || ! preg_match('/(?:^|-)b(\d+)-t([1-5])$/', $t->reference, $m) || (int) $m[1] !== $t->block) {
            return null;
        }
        $b = collect($plan['blocks'])->firstWhere('number', $t->block);
        if (! $b || $t->block > 10) {
            return null;
        }
        $start = CarbonImmutable::parse($b['start']);
        $end = CarbonImmutable::parse($b['end']);
        $fraction = in_array((int) $m[2], [1, 5], true) ? 1 : ((int) $m[2] - 1) / 4;

        return $start->addDays((int) floor($start->diffInDays($end) * $fraction))->toDateString();
    }

    public static function preview(Apprentice $a, ?array $plan): array
    {
        if (! $plan) {
            return [];
        }

        return LearningTask::where('apprentice_id', $a->id)->orderBy('block')->orderBy('reference')->get()->map(fn ($t) => ['id' => $t->id, 'reference' => $t->reference, 'title' => $t->title, 'due' => self::deadline($t, $plan)])->filter(fn ($t) => $t['due'] !== null)->values()->all();
    }
}
