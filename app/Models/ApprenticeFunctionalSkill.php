<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprenticeFunctionalSkill extends Model
{
    use HasFactory;

    protected $primaryKey = 'afs_id';

    protected $fillable = [
        'apprentice_id',
        'subject',
        'topic',
        'area',
        'planned_hours',
        'status',
        'completion_date',
    ];

    protected $casts = [
        'planned_hours' => 'decimal:1',
        'completion_date' => 'date',
    ];

    /**
     * Preserved Rule from RULES_AND_PERMISSIONS.txt:
     * Zero assigned hours means secure / no training needed in that topic.
     * Unknown hours are not zero.
     */
    public function getIsSecureAttribute(): bool
    {
        return (float) $this->planned_hours === 0.0;
    }

    public function apprentice(): BelongsTo
    {
        return $this->belongsTo(Apprentice::class, 'apprentice_id', 'apprentice_id');
    }
}

