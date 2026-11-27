<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\IndividualDeliveryPlan;
use App\Models\LearningTask;
use App\Models\ProgrammeSetupTemplate68;
use App\Models\TrainingPlanWorkspace;
use Carbon\CarbonImmutable;

class DeliveryPlan
{
    public static function identity(Apprentice $a): array
    {
        $p = ReportRead85::programme($a);

        return ['programme_id' => $a->programme_id, 'catalogue' => TaskCatalogue::keyFor($a), 'code' => $p?->standard_code, 'version' => $p?->version, 'active' => $p?->active, 'start' => $a->start_date?->toDateString(), 'employer_id' => $a->employer_id, 'employer' => $a->employerLabel()];
    }

    public static function identityMatches(Apprentice $apprentice, ?array $saved): bool
    {
        return $saved !== null && hash_equals(ReviewedImports::hash(self::identity($apprentice)), ReviewedImports::hash($saved));
    }

    public static function catalogueHash(string $key): string
    {
        return hash('sha256', file_get_contents(resource_path('data/'.$key.'-tasks.json')).file_get_contents(resource_path('data/'.$key.'-catalogue.json')));
    }

    public static function draftHash(array $draft): string
    {
        return hash('sha256', json_encode($draft, JSON_THROW_ON_ERROR));
    }

    public static function approved(Apprentice $a): ?array
    {
        $row = ReportRead85::rows(IndividualDeliveryPlan::class, $a->id, fn () => IndividualDeliveryPlan::where('apprentice_id', $a->id)->get())->first();
        if (! $row?->active || ! $row->approved || ! self::identityMatches($a, $row->approved['identity'] ?? null)) {
            return null;
        }

        if ($binding = $row->approved['rpl_binding'] ?? null) {
            $position = self::rplPosition($a, $binding);
            if (! $position || ! self::targetMatches($position, $row->approved['details']['target_hours'] ?? null)) {
                return null;
            }
        }

        // A later blank-task release does not rewrite an already approved individual plan.
        return $row->approved;
    }

    public static function rplPosition(Apprentice $apprentice, string $binding, bool $lock = false): ?array
    {
        $query = TrainingPlanWorkspace::where('apprentice_id', $apprentice->id);
        $workspace = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $workspace) {
            return null;
        }
        // Guided setup and the earlier assessed Autocare workflow issue different bindings.
        if (class_exists(Rpl68::class) && $workspace->getRawOriginal('setup_rpl') !== null) {
            $setup = $workspace->setup_rpl;
            if (is_array($setup) && hash_equals($binding, Rpl68::hash($setup))) {
                $template = ProgrammeSetupTemplate68::find($setup['template_id'] ?? 0);
                if (! $template || $template->programme_id !== $apprentice->programme_id) {
                    return null;
                }
                $position = Rpl68::position($workspace);

                return $position['approved'] ? $position : null;
            }
        }

        $position = TrainingPlanSource::position($workspace);

        return $position['approved'] && hash_equals($binding, $position['binding']) ? $position : null;
    }

    public static function targetMatches(array $position, mixed $target): bool
    {
        return is_numeric($target) && is_numeric($position['remaining_hours'] ?? null)
            && abs((float) $target - (float) $position['remaining_hours']) <= 0.005001;
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
