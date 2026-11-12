<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MonthlyScorecard extends Model
{
    use HasFactory;

    protected $attributes = ['version' => 1];

    protected $fillable = ['apprentice_id', 'tutor_id', 'training_session_id', 'month', 'scores', 'comment'];

    protected function casts(): array
    {
        return ['scores' => 'array', 'history' => 'array', 'version' => 'integer'];
    }
}
