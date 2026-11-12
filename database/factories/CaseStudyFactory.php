<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CaseStudyFactory extends Factory
{
    public function definition(): array
    {
        return ['author_id' => User::factory(), 'fields' => ['title' => 'Fictional anonymised case', 'category' => 'Attendance and engagement']];
    }
}
