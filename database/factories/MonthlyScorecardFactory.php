<?php

namespace Database\Factories;

use App\Models\Apprentice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class MonthlyScorecardFactory extends Factory
{
    public function definition(): array
    {
        return ['apprentice_id' => Apprentice::factory(), 'tutor_id' => User::factory(), 'month' => today()->format('Y-m'), 'scores' => ['engagement' => null, 'preparation' => null, 'respect' => null, 'safety' => null, 'instructions' => null], 'comment' => 'Not enough observed evidence this month'];
    }
}
