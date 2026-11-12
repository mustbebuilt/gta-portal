<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Apprentice extends Model
{
    use HasFactory;

    protected $attributes = ['version' => 1];

    protected $fillable = ['reference', 'first_name', 'last_name', 'email', 'programme', 'start_date', 'planned_end_date', 'employer', 'group_name', 'tutor_id', 'programme_id', 'employer_id', 'tutor_group_id', 'mentor_id', 'careers_adviser_id', 'maths_tutor_id', 'english_tutor_id'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'planned_end_date' => 'date', 'version' => 'integer'];
    }

    public function tutor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tutor_id');
    }

    public function programmeRecord(): BelongsTo
    {
        return $this->belongsTo(Programme::class, 'programme_id');
    }

    public function employerRecord(): BelongsTo
    {
        return $this->belongsTo(Employer::class, 'employer_id');
    }

    public function groupRecord(): BelongsTo
    {
        return $this->belongsTo(TutorGroup::class, 'tutor_group_id');
    }

    public function mentor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mentor_id');
    }

    public function careersAdviser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'careers_adviser_id');
    }

    public function mathsTutor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'maths_tutor_id');
    }

    public function englishTutor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'english_tutor_id');
    }

    public function programmeLabel(): ?string
    {
        return $this->programmeRecord?->name ?? $this->programme;
    }

    public function employerLabel(): ?string
    {
        return $this->employerRecord?->name ?? $this->employer;
    }

    public function groupLabel(): ?string
    {
        return $this->groupRecord?->name ?? $this->group_name;
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return match ($user->role) {
            'admin', 'ceo' => $query,
            'tutor' => $query->where('tutor_id', $user->id),
            'apprentice' => $query->where('user_id', $user->id),
            default => $query->whereRaw('1 = 0'),
        };
    }
}
