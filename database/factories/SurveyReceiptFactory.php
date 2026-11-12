<?php

namespace Database\Factories;

use App\Models\Apprentice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SurveyReceiptFactory extends Factory
{
    public function definition(): array
    {
        return ['apprentice_id' => Apprentice::factory(), 'recorded_by' => User::factory(), 'kind' => 'sixweek', 'submitted_date' => today()];
    }
}
