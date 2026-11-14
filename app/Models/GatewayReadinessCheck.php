<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GatewayReadinessCheck extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['checked_on' => 'date', 'version' => 'integer', 'history' => 'array'];
    }
}
