<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportMarkerSchedule71 extends Model
{
    protected $table = 'support_marker_schedules71';

    protected $guarded = ['*'];

    protected $hidden = ['history'];

    protected function casts(): array
    {
        return [
            'apprentice_id' => 'integer',
            'support_concern_id' => 'integer',
            'first_review_completed' => 'boolean',
            'last_formal_review_date' => 'date',
            'next_due_date' => 'date',
            'version' => 'integer',
            'history' => 'encrypted:array',
        ];
    }

    public function apprentice(): BelongsTo
    {
        return $this->belongsTo(Apprentice::class);
    }

    public function concern(): BelongsTo
    {
        return $this->belongsTo(SupportConcern::class, 'support_concern_id');
    }
}
