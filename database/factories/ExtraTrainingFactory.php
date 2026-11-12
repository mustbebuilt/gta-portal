<?php

namespace Database\Factories;

use App\Models\Apprentice;
use App\Models\TutorGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExtraTrainingFactory extends Factory
{
    public function definition(): array
    {
        return ['apprentice_id' => Apprentice::factory(), 'tutor_group_id' => TutorGroup::factory(), 'training_date' => today()->subDay(), 'start_time' => '17:00', 'end_time' => '19:00', 'excluded_minutes' => 0, 'minutes' => 120, 'parts' => [[1020, 1140]], 'topic' => 'Fictional extra diagnostic learning', 'provider' => 'Fictional GTA training', 'learning' => 'New occupational diagnostic method', 'evidence' => 'Fictional training source reference', 'working' => 'outside', 'recorded_by' => User::factory()];
    }
}
