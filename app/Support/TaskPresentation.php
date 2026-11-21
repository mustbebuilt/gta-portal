<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class TaskPresentation
{
    private static ?array $cachedManifest = null;

    /** Read the controlled task-presentation manifest once per request. */
    public static function manifest(): array
    {
        if (self::$cachedManifest === null) {
            $path = resource_path('data/task-presentation.json');
            self::$cachedManifest = is_file($path)
                ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)
                : ['programmes' => []];
        }

        return self::$cachedManifest;
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
            if (! is_string($id) || ! in_array($id, $steps, true)) {
                throw new InvalidArgumentException('Choose only activities listed on this task.');
            }
            $checked[$id] = true;
        }

        return array_values(array_filter($steps, static fn (string $id): bool => isset($checked[$id])));
    }
}
