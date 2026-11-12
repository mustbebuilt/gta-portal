<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ApprenticeFactory extends Factory
{
    public function definition(): array
    {
        return ['reference' => 'TEST-'.fake()->unique()->numerify('########'), 'first_name' => 'Fictional', 'last_name' => 'Apprentice'];
    }
}
