<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\TrainingSession;
use App\Models\TutorGroup;
use Carbon\CarbonImmutable;

final class SessionPlanning
{
    public static function options(iterable $members, ?TutorGroup $group): array
    {
        $subjects = RegisterTraining::options($members);
        $groupCatalogue = $group?->programme_id
            ? TaskCatalogue::allocationKey(new Apprentice(['programme_id' => $group->programme_id])) : null;
        $catalogues = collect($members)->map(fn ($member) => TaskCatalogue::allocationKey($member))
            ->push($groupCatalogue)->filter()->unique();
        foreach ($catalogues as $catalogue) {
            $programme = TaskCatalogue::PROGRAMMES[$catalogue];
            foreach (TaskCatalogue::tasks($catalogue) as $task) {
                $id = 'voc-'.$catalogue.'-'.$task['id'];
                $subjects[$id] ??= [
                    'subjectId' => $id,
                    'label' => $programme['code'].' · Block '.$task['block'].' · '.$task['title'],
                    'area' => 'vocational',
                ];
            }
        }

        return $subjects;
    }

    public static function deliveryOptions(TrainingSession $session, iterable $members, array $saved): array
    {
        $options = self::options($members, $session->group);
        $presets = [
            'functional_skills' => TrainingJourney72::FUNCTIONAL_SKILLS,
            'themes' => array_intersect_key(TrainingJourney72::CROSS_CUTTING, array_flip([
                'cc-edi', 'cc-british-values', 'cc-safeguarding',
            ])),
        ];
        foreach ($presets as $area => $items) {
            foreach ($items as $id => $label) {
                $options[$id] ??= ['subjectId' => $id, 'label' => $label, 'area' => $area];
            }
        }
        foreach ([...($saved['items'] ?? []), ...$session->planned_subjects] as $item) {
            if (!str_starts_with($item['subjectId'], 'custom:')) {
                $options[$item['subjectId']] ??= $item;
            }
        }

        return $options;
    }

    public static function weekly(TutorGroup $group, string $date): ?array
    {
        $occurrence = WeeklyRegisters::next($group, CarbonImmutable::parse($date, config('app.timezone')));

        return ($occurrence['date'] ?? null) === $date ? $occurrence : null;
    }
}
