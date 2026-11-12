<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExtraTraining extends Model
{
    use HasFactory;

    protected $attributes = ['version' => 1];

    protected $fillable = ['apprentice_id', 'tutor_group_id', 'training_date', 'start_time', 'end_time', 'excluded_minutes', 'minutes', 'parts', 'topic', 'provider', 'learning', 'evidence', 'working', 'recorded_by'];

    protected function casts(): array
    {
        return ['training_date' => 'date', 'excluded_minutes' => 'integer', 'minutes' => 'integer', 'parts' => 'array', 'agreement' => 'array', 'compensation' => 'array', 'approval' => 'array', 'file' => 'array', 'history' => 'array', 'withdrawn_at' => 'datetime', 'version' => 'integer'];
    }

    public function apprentice(): BelongsTo
    {
        return $this->belongsTo(Apprentice::class);
    }

    public function occurred(): bool
    {
        return now()->gte(Carbon::parse($this->training_date->toDateString().' '.$this->end_time, config('app.timezone')));
    }

    public function position(): string
    {
        if ($this->withdrawn_at) {
            return 'Withdrawn';
        }if ($this->approval) {
            return 'Approved OTJ';
        }
        if ($this->working === 'outside') {
            if (! $this->agreement) {
                return 'Awaiting apprentice agreement';
            } if (! $this->compensation || $this->compensation['covered'] < $this->minutes) {
                return 'Awaiting employer confirmation';
            }
        }

        return 'Ready for tutor check';
    }
}
