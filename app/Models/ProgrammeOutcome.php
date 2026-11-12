<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProgrammeOutcome extends Model
{
    use HasFactory;

    protected $attributes = ['version' => 1];

    protected $fillable = ['apprentice_id', 'context'];

    protected function casts(): array
    {
        return ['context' => 'array', 'history' => 'array', 'version' => 'integer'];
    }
}
