<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WeeklyRetentionCheck extends Model
{
    public $timestamps = false;

    protected $fillable = ['apprentice_id', 'week_of', 'evidence'];

    protected $casts = ['evidence' => 'array', 'reviewed_at' => 'datetime'];

    public function apprentice(): BelongsTo
    {
        return $this->belongsTo(Apprentice::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(WeeklyRetentionReminder::class);
    }
}
