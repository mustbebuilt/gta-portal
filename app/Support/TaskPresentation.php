<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\LearningTask;
use InvalidArgumentException;

final class TaskPresentation
{
    public static function manifest(): array
    {
        static $manifest;

        return $manifest ??= json_decode(file_get_contents(resource_path('data/task-presentation.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function forTask(LearningTask $task): ?array
    {
        $manifest = self::manifest();
        $programme = TaskCatalogue::savedKey($task);
        $reference = $task->reference;
        $prefix = $programme.'-';
        if (str_starts_with($reference, $prefix)) {
            $reference = substr($reference, strlen($prefix));
        }

        return self::forSavedTask($manifest, $programme, $reference, $task->template);
    }

    public static function checklist(?array $presentation): array
    {
        return ($presentation['useExistingStructuredChecks'] ?? false) ? [] : ($presentation['checklist'] ?? []);
    }

    public static function apprenticeFields(LearningTask $task, ?array $presentation): array
    {
        $fields = array_values(array_filter($task->template['fields'], fn ($field) => $field['role'] === 'apprentice'));
        if (($presentation['kind'] ?? null) !== 'practical') {
            return $fields;
        }

        $vehicle = [];
        $inspection = [];
        $other = [];
        foreach ($fields as $field) {
            $key = $field['key'];
            if ($field['section'] === 'Vehicle details' || str_starts_with($key, 'vehicle_')
                || in_array($key, ['chassis_number', 'mileage', 'job_card', 'panel_area',
                    'detail_1_right', 'detail_2_left', 'detail_2_right', 'detail_3_left', 'detail_3_right'], true)) {
                $field['section'] = TaskCatalogue::savedKey($task) === 'heavy-vehicle' ? 'Vehicle or trailer details' : 'Vehicle details';
                $vehicle[] = $field;
            } elseif (str_starts_with($field['section'], 'Inspection checklist')) {
                $inspection[] = $field;
            } else {
                $other[] = $field;
            }
        }

        return [...$vehicle, ...$inspection, ...$other];
    }

    public static function recordedActivities(array $answers): array
    {
        return $answers['_activities'] ?? [];
    }

    /**
     * Resolve optional presentation against the saved task template, not the
     * currently active programme or a template name supplied by the browser.
     */
    public static function forSavedTask(array $manifest, string $programme, string $reference, array $template): ?array
    {
        $entry = $manifest['programmes'][$programme]['tasks'][$reference] ?? null;
        if ($entry === null || $entry['kind'] !== ($template['kind'] ?? null)
            || $entry['filename'] !== ($template['filename'] ?? null)) {
            return null;
        }
        $hash = $template['sha256'] ?? null;
        return is_string($hash) && in_array($hash, $entry['templateHashes'], true) ? $entry : null;
    }

    /** Validate a complete checkbox list from a form that rendered the matched checklist. */
    public static function checkedSteps(array $presentation, array $posted): array
    {
        $steps = array_column($presentation['checklist'], 'id');
        $checked = [];
        foreach ($posted as $id) {
            if (!is_string($id) || !in_array($id, $steps, true)) {
                throw new InvalidArgumentException('Choose only activities listed on this task.');
            }
            $checked[$id] = true;
        }

        return array_values(array_filter($steps, static fn (string $id): bool => isset($checked[$id])));
    }
}
