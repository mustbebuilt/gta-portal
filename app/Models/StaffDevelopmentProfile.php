<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffDevelopmentProfile extends Model
{
    protected $attributes = ['version' => 1];

    protected $fillable = ['user_id', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'version' => 'integer'];
    }
}
