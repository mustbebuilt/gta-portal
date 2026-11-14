<?php

namespace App\Support;

use App\Models\User;

class TaskLibrary
{
    public static function allowed(User $user): bool
    {
        return $user->active && in_array($user->role, ['admin', 'ceo', 'tutor'], true);
    }

    public static function entry(string $programme, string $reference): array
    {
        abort_unless(isset(TaskCatalogue::PROGRAMMES[$programme]), 404);
        $release = CurriculumSources::release($programme);
        abort_unless($release['technical_ok'], 409, 'This source catalogue or its PDFs need checking.');
        $task = collect($release['tasks'])->firstWhere('id', $reference);
        abort_unless($task, 404);

        return ['key' => $programme, 'task' => $task, 'catalogue' => TaskCatalogue::PROGRAMMES[$programme],
            'template' => TaskCatalogue::template($reference, $programme), 'release' => CurriculumSources::safeRelease($release)];
    }
}
