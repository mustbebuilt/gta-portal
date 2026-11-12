<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmartAction extends Model
{
    use HasFactory;

    public const OWNERS = ['Apprentice', 'Tutor', 'Learning & Progress Mentor', 'Employer / workplace mentor'];

    protected $attributes = ['status' => 'To do', 'version' => 1];

    protected $fillable = ['apprentice_id', 'learning_task_id', 'created_by', 'title', 'owner', 'due_date', 'method', 'measure', 'evidence_method', 'relevance', 'learning_reference', 'support', 'smart_data'];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'completion_date' => 'date', 'smart_data' => 'array', 'history' => 'array', 'version' => 'integer'];
    }

    public function apprentice(): BelongsTo
    {
        return $this->belongsTo(Apprentice::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(LearningTask::class, 'learning_task_id');
    }

    public function currentStatus(): string
    {
        if (! $this->learning_task_id) {
            return $this->status;
        }

return match ($this->task?->status) {
            'Approved' => 'Completed','Awaiting marking' => 'With tutor',default => 'To do'
        };
    }

    public function deadline(): ?Carbon
    {
        return $this->learning_task_id ? $this->task?->due_date : $this->due_date;
    }

    public function overdue(): bool
    {
        return $this->currentStatus() !== 'Completed' && $this->deadline() && $this->deadline()->lt(today());
    }
}
