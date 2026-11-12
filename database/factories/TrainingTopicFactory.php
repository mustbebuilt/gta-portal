<?php

namespace Database\Factories;

use App\Models\Apprentice;
use Illuminate\Database\Eloquent\Factories\Factory;

class TrainingTopicFactory extends Factory
{
    public function definition(): array
    {
        return ['apprentice_id' => Apprentice::factory(), 'subject' => 'maths', 'level' => 'Level 2', 'topic' => 'Circles', 'area' => 'Common Measures, Shape and Space', 'planned_hours' => 1.5];
    }
}
