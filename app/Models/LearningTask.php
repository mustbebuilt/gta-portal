<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LearningTask extends Model
{
    use HasFactory;

    protected $attributes = ['status' => 'Draft', 'version' => 1];

    protected $fillable = ['apprentice_id', 'reference', 'block', 'title', 'theme', 'template', 'answers', 'due_date'];

    protected function casts(): array
    {
        return ['template' => 'array', 'answers' => 'array', 'submissions' => 'array', 'deadline_history' => 'array', 'due_date' => 'date', 'submitted_at' => 'datetime', 'block' => 'integer', 'version' => 'integer'];
    }

    public function apprentice(): BelongsTo
    {
        return $this->belongsTo(Apprentice::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(TaskEvidence::class);
    }

    public function editable(): bool
    {
        return in_array($this->status, ['Draft', 'Referred'], true);
    }

    public function markingOverdue(): bool
    {
        return $this->status === 'Awaiting marking' && $this->submitted_at && now()->gt($this->submitted_at->copy()->addDays(7));
    }
}
