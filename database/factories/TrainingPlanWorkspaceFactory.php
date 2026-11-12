<?php

namespace Database\Factories;

use App\Models\Apprentice;
use App\Support\TrainingPlanSource;
use Illuminate\Database\Eloquent\Factories\Factory;

class TrainingPlanWorkspaceFactory extends Factory
{
    public function definition(): array
    {
        return ['apprentice_id' => Apprentice::factory(), 'source_hash' => TrainingPlanSource::hash(), 'proposal' => [], 'rpl' => []];
    }
}
