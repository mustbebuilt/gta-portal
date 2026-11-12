<?php

namespace App\Support;

class TaskCatalogue
{
    public static function tasks(): array
    {
        return json_decode(file_get_contents(resource_path('data/autocare-tasks.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function template(string $id): array
    {
        $c = json_decode(file_get_contents(resource_path('data/autocare-catalogue.json')), true, 512, JSON_THROW_ON_ERROR);

        return $c['templates'][$c['tasks'][$id]];
    }
}
