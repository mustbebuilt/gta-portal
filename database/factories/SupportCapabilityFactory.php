<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SupportCapabilityFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'duty' => 'staff', 'scope' => 'assigned', 'apprentice_ids' => [], 'group_ids' => []];
    }
}
