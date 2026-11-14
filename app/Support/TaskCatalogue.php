<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\LearningTask;

class TaskCatalogue
{
    public const PROGRAMMES = [
        'autocare' => ['code' => 'ST0499', 'version' => '1.3', 'name' => 'Autocare Level 2'],
        'heavy-vehicle' => ['code' => 'ST0068', 'version' => '1.5', 'name' => 'Heavy Vehicle Level 3'],
        'panel' => ['code' => 'ST0403', 'version' => '1.3', 'name' => 'Vehicle Damage Panel Level 3'],
        'paint' => ['code' => 'ST0448', 'version' => '1.2', 'name' => 'Vehicle Damage Paint Level 3'],
    ];

    public static function keyFor(Apprentice $apprentice): ?string
    {
        // Retain the original unmapped local Autocare workflow; new programmes need an explicit link.
        if (! $apprentice->programme_id) {
            return 'autocare';
        }
        $p = $apprentice->programmeRecord()->first();
        if (! $p || ! $p->active) {
            return null;
        }
        foreach (self::PROGRAMMES as $key => $source) {
            if (strtoupper(trim($p->standard_code)) === $source['code'] && in_array(strtolower(trim($p->version)), [$source['version'], 'v'.$source['version']], true)) {
                return $key;
            }
        }

        return null;
    }

    public static function appliesTo(Apprentice $apprentice): bool
    {
        return self::keyFor($apprentice) !== null;
    }

    // New allocations require an explicit programme/version. Legacy saved Autocare work remains readable.
    public static function allocationKey(Apprentice $apprentice): ?string
    {
        return $apprentice->programme_id ? self::keyFor($apprentice) : null;
    }

    public static function savedKey(LearningTask $task): string
    {
        return $task->template['catalogueId'] ?? 'autocare';
    }

    public static function historyMatches(Apprentice $apprentice): bool
    {
        $key = self::keyFor($apprentice);

        return $key !== null && LearningTask::where('apprentice_id', $apprentice->id)->get()->every(fn ($task) => self::savedKey($task) === $key);
    }

    public static function tasks(string $key = 'autocare'): array
    {
        self::requireKey($key);

        return json_decode(file_get_contents(resource_path('data/'.$key.'-tasks.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function template(string $id, string $key = 'autocare'): array
    {
        self::requireKey($key);
        $c = json_decode(file_get_contents(resource_path('data/'.$key.'-catalogue.json')), true, 512, JSON_THROW_ON_ERROR);

        return $c['templates'][$c['tasks'][$id]];
    }

    private static function requireKey(string $key): void
    {
        if (! array_key_exists($key, self::PROGRAMMES)) {
            throw new \InvalidArgumentException('Task catalogue is not loaded.');
        }
    }
}
