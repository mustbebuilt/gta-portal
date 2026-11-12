<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ExecutiveAccessSessionFactory extends Factory
{
    public function definition(): array
    {
        return ['reference' => (string) Str::uuid(), 'user_id' => User::factory(), 'reason' => 'Fictional emergency access test', 'started_at' => now()];
    }
}
