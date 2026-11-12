<?php

namespace Database\Factories;

use App\Models\TutorGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TrainingSessionFactory extends Factory
{
    public function definition(): array
    {
        return ['tutor_group_id' => TutorGroup::factory(), 'created_by' => User::factory(), 'name' => 'Fictional training session', 'session_date' => '2026-11-01', 'start_time' => '09:00', 'end_time' => '16:00', 'planned_learning' => 'Inspection methods and safe working', 'breaks' => [['start' => '12:00', 'end' => '12:30']], 'members' => []];
    }
}
