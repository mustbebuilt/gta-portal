<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingTopic extends Model
{
    use HasFactory;

    protected $attributes = ['version' => 1, 'status' => 'Not Started', 'evidence_stage' => 'Draft'];

    protected $fillable = ['apprentice_id', 'subject', 'level', 'topic', 'area', 'planned_hours'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'planned_hours' => 'decimal:2', 'completion_date' => 'date', 'files' => 'array', 'submissions' => 'array', 'history' => 'array'];
    }

    public function apprentice(): BelongsTo
    {
        return $this->belongsTo(Apprentice::class);
    }

    public function editable(): bool
    {
        return $this->status !== 'Completed' && $this->evidence_stage !== 'Awaiting review';
    }
}
