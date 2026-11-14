<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprenticeAlertDelivery extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['cycle_date' => 'date', 'source' => 'array', 'preference_revision' => 'integer', 'auth_version' => 'integer', 'reserved_at' => 'datetime', 'completed_at' => 'datetime', 'seen_at' => 'datetime', 'reconciliation' => 'array', 'history' => 'array'];
    }
}
