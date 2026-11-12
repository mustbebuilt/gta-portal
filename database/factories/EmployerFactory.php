<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class EmployerFactory extends Factory
{
    public function definition(): array
    {
        return ['reference' => 'EMP-'.fake()->unique()->numerify('########'), 'name' => 'Fictional Employer'];
    }
}
