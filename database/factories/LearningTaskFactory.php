<?php

namespace Database\Factories;

use App\Models\Apprentice;
use App\Support\TaskCatalogue;
use Illuminate\Database\Eloquent\Factories\Factory;

class LearningTaskFactory extends Factory
{
    public function definition(): array
    {
        return ['apprentice_id' => Apprentice::factory(), 'reference' => 'b1-t3', 'block' => 1, 'title' => 'Task 3: Vehicle safety inspection', 'theme' => 'Fictional allocation', 'template' => TaskCatalogue::template('b1-t3')];
    }
}
