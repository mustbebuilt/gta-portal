<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprenticeTask extends Model
{
    use HasFactory;

    protected $primaryKey = 'at_id';

    protected $fillable = [
        'apprentice_id',
        'ablk_id',
        'task_num',
        'task_type',
        'theme',
        'due_date',
        'status',
        'progress',
        'completion_date',
        'submitted_at',
        'feedback_trainer_id',
        'feedback_date',
        'feedback',
        'knowledge_score',
    ];

    protected $casts = [
        'due_date' => 'date',
        'completion_date' => 'date',
        'submitted_at' => 'datetime',
        'feedback_date' => 'datetime',
        'progress' => 'decimal:2',
        'knowledge_score' => 'integer',
        'task_num' => 'integer',
        'ablk_id' => 'integer',
    ];

    public function apprentice(): BelongsTo
    {
        return $this->belongsTo(Apprentice::class, 'apprentice_id', 'apprentice_id');
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'feedback_trainer_id', 'id');
    }

    public function uploads(): HasMany
    {
        return $this->hasMany(ApprenticeTaskUpload::class, 'at_id', 'at_id');
    }

    public function activeUploads(): HasMany
    {
        return $this->uploads()->whereNull('superseded_at');
    }

    /**
     * GTA SLA Rule: Waiting > 7 days is overdue; due today is not overdue.
     */
    public function getIsOverdueAttribute(): bool
    {
        if ($this->status !== 'Submitted' || !$this->submitted_at) {
            return false;
        }

        return $this->submitted_at->diffInDays(now()) > 7;
    }

    public function scopePendingMarking(Builder $query): Builder
    {
        return $query->where('status', 'Submitted');
    }

    public function scopeOverdueMarking(Builder $query): Builder
    {
        return $query->where('status', 'Submitted')
            ->where('submitted_at', '<', Carbon::now()->subDays(7));
    }
}

