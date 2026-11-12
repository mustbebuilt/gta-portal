<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceMark extends Model
{
    protected $fillable = ['training_session_id', 'apprentice_id', 'code', 'arrival', 'departure', 'otj_minutes', 'parts', 'comment', 'adjustment', 'otj_confirmed', 'recorded_by'];

    protected function casts(): array
    {
        return ['otj_confirmed' => 'boolean', 'parts' => 'array', 'otj_minutes' => 'integer'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class, 'training_session_id');
    }

    public function apprentice(): BelongsTo
    {
        return $this->belongsTo(Apprentice::class);
    }
}
