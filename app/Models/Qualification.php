<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Qualification extends Model
{
    use HasFactory;

    public const STATUSES = ['Working towards', 'Achieved', 'Already held', 'Exemption confirmed', 'Awaiting confirmation'];

    protected $attributes = ['version' => 1];

    protected $fillable = ['apprentice_id', 'subject', 'type', 'title', 'level', 'status', 'result', 'start_date', 'achievement_date', 'notes'];

    protected function casts(): array
    {
        return ['files' => 'array', 'version' => 'integer', 'start_date' => 'date', 'achievement_date' => 'date', 'history' => 'array'];
    }

    public function apprentice(): BelongsTo
    {
        return $this->belongsTo(Apprentice::class);
    }

    public function target(): ?Carbon
    {
        return $this->start_date?->copy()->addYearNoOverflow();
    }

    public function achieved(): bool
    {
        return in_array($this->status, ['Achieved', 'Already held'], true);
    }

    public function position(): string
    {
        if ($this->achieved()) {
            return 'Achieved';
        }if ($this->status === 'Exemption confirmed') {
            return 'Exemption confirmed';
        }if (! $this->target()) {
            return 'Date check';
        }if ($this->target()->lt(today())) {
            return 'Overdue';
        }if ($this->target()->isToday()) {
            return 'Due today';
        }

        return 'Within target';
    }
}
