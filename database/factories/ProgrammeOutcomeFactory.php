<?php

namespace Database\Factories;

use App\Models\Apprentice;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProgrammeOutcomeFactory extends Factory
{
    public function definition(): array
    {
        return ['apprentice_id' => Apprentice::factory(), 'context' => ['status' => 'Unknown', 'achievement' => 'Unknown']];
    }
}
