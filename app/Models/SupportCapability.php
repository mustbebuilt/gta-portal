<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupportCapability extends Model
{
    use HasFactory;

    protected $attributes = ['version' => 1];

    protected $fillable = ['user_id', 'duty', 'scope', 'apprentice_ids', 'group_ids'];

    protected function casts(): array
    {
        return ['apprentice_ids' => 'array', 'group_ids' => 'array', 'version' => 'integer'];
    }
}
