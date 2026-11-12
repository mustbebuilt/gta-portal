<?php

namespace Database\Factories;

use App\Models\Apprentice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProgressReviewDraftFactory extends Factory
{
    public function definition(): array
    {
        return ['apprentice_id' => Apprentice::factory(), 'author_id' => User::factory(), 'fields' => [], 'source_snapshot' => ['captured_at' => now()->toIso8601String(), 'whole_plan' => 'Not linked']];
    }
}
