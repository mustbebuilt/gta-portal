<?php

namespace Database\Factories;

use App\Models\Apprentice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CareerDiscussionFactory extends Factory
{
    public function definition(): array
    {
        return ['apprentice_id' => Apprentice::factory(), 'adviser_id' => User::factory(), 'recorded_by' => User::factory(), 'type' => 'progression', 'discussion_date' => '2026-11-01', 'goals' => 'Explore next occupational step', 'options' => 'Further training options', 'advice' => 'Compare the available training routes', 'outcome' => 'Exploring options', 'next_step' => 'Discuss preferences at next adviser appointment'];
    }
}
