<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GatewayReadinessPlan extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['basis' => 'array', 'criteria' => 'array', 'approved_at' => 'datetime', 'version' => 'integer', 'history' => 'array'];
    }
}
