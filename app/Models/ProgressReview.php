<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProgressReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'apprentice_id',
        'trainer_id',
        'review_sequence',
        'due_date',
        'meeting_date',
        'progress_summary',
        'status',
    ];

    protected $casts = [
        'due_date' => 'date',
        'meeting_date' => 'date',
        'review_sequence' => 'integer',
    ];

    public function apprentice(): BelongsTo
    {
        return $this->belongsTo(Apprentice::class, 'apprentice_id', 'apprentice_id');
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trainer_id', 'id');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(ReviewSignature::class, 'review_id', 'id');
    }
}

