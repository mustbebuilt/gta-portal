<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LearningBlockControl extends Model
{
    protected $attributes = ['version' => 1, 'enabled' => true];

    protected $fillable = ['apprentice_id', 'enabled', 'exceptions', 'history'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'exceptions' => 'array', 'history' => 'array', 'version' => 'integer'];
    }
}
