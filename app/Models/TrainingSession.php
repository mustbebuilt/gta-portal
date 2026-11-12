<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingSession extends Model
{
    use HasFactory;

    protected $attributes = ['version' => 1, 'state' => 'Planned'];

    protected $fillable = ['tutor_group_id', 'created_by', 'name', 'session_date', 'start_time', 'end_time', 'planned_learning', 'breaks', 'members'];

    protected function casts(): array
    {
        return ['session_date' => 'date', 'breaks' => 'array', 'members' => 'array', 'history' => 'array', 'version' => 'integer'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(TutorGroup::class, 'tutor_group_id');
    }

    public function marks(): HasMany
    {
        return $this->hasMany(AttendanceMark::class);
    }

    public function ended(): bool
    {
        return now()->gte(Carbon::parse($this->session_date->toDateString().' '.$this->end_time, config('app.timezone')));
    }
}
