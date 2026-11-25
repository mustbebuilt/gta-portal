<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployerPortalAction extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function apprentice(): BelongsTo
    {
        return $this->belongsTo(Apprentice::class);
    }

    protected function casts(): array
    {
        return ['source' => 'array', 'due_date' => 'date', 'responded_at' => 'datetime', 'version' => 'integer', 'history' => 'array'];
    }
}
