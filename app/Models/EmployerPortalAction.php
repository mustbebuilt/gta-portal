<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployerPortalAction extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['source' => 'array', 'due_date' => 'date', 'responded_at' => 'datetime', 'version' => 'integer', 'history' => 'array'];
    }
}
