<?php

namespace Database\Factories;

use App\Models\Apprentice;
use Illuminate\Database\Eloquent\Factories\Factory;

class QualificationFactory extends Factory
{
    public function definition(): array
    {
        return ['apprentice_id' => Apprentice::factory(), 'subject' => 'maths', 'type' => 'Functional Skills', 'title' => 'Functional Skills Maths', 'level' => 'Level 2', 'status' => 'Working towards', 'start_date' => '2026-11-01'];
    }
}
