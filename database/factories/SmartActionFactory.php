<?php

namespace Database\Factories;

use App\Models\Apprentice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SmartActionFactory extends Factory
{
    public function definition(): array
    {
        return ['apprentice_id' => Apprentice::factory(), 'created_by' => User::factory(), 'title' => 'Complete five practice questions', 'owner' => 'Apprentice', 'due_date' => today()->addDays(14), 'method' => 'quantity', 'measure' => '5 questions', 'evidence_method' => 'worksheet', 'relevance' => 'block', 'learning_reference' => 'Fictional block 1', 'support' => 'Tutor session', 'smart_data' => ['quantity' => 5, 'unit' => 'questions', 'achievable' => true, 'agreed' => true]];
    }
}
