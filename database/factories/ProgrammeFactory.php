<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ProgrammeFactory extends Factory
{
    public function definition(): array
    {
        return ['standard_code' => 'TEST-'.fake()->unique()->numerify('########'), 'version' => 'demo-1', 'name' => 'Fictional Programme', 'level' => 2];
    }
}
