<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class TutorGroupFactory extends Factory
{
    public function definition(): array
    {
        return ['reference' => 'GROUP-'.fake()->unique()->numerify('########'), 'name' => 'Fictional Group'];
    }
}
