<?php

namespace Database\Factories;

use App\Models\Apprentice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SupportConcernFactory extends Factory
{
    public function definition(): array
    {
        return ['apprentice_id' => Apprentice::factory(), 'raised_by' => User::factory(), 'initial_note' => 'Fictional concern requiring inclusion follow-up'];
    }
}
