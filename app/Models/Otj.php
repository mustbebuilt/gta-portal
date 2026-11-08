<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Otj extends Model
{
    use HasFactory;

    protected $table = 'otj';
    protected $primaryKey = 'otj_id';

    protected $fillable = [
        'uid',
        'time_chunks',
        'date_of_otj',
        'start_time',
        'end_time',
        'otj_type',
        'otj_activity_description',
        'otj_trainer_sign_off',
    ];

    protected $casts = [
        'date_of_otj' => 'date',
        'time_chunks' => 'integer',
        'otj_trainer_sign_off' => 'boolean',
    ];

    /**
     * Convert 15-minute time chunks into decimal hours.
     * e.g. 4 chunks = 1.00 hour, 24 chunks = 6.00 hours.
     */
    public function getHoursAttribute(): float
    {
        return $this->time_chunks * 0.25;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uid', 'id');
    }
}

