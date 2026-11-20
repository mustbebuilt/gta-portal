<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportSharedAction71 extends Model
{
    protected $table = 'support_shared_actions71';

    protected $guarded = ['*'];

    protected $hidden = ['details', 'history'];

    protected function casts(): array
    {
        return [
            'apprentice_id' => 'integer',
            'owner_id' => 'integer',
            'created_by' => 'integer',
            'support_concern_id' => 'integer',
            'support_plan_id' => 'integer',
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'version' => 'integer',
            'details' => 'encrypted:array',
            'history' => 'encrypted:array',
        ];
    }

    public function apprentice(): BelongsTo
    {
        return $this->belongsTo(Apprentice::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
