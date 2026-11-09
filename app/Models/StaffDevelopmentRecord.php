<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffDevelopmentRecord extends Model
{
    use HasFactory;

    protected $primaryKey = 'record_id';

    protected $fillable = [
        'user_id',
        'manager_id',
        'category',
        'title',
        'record_date',
        'feedback',
        'evidence_ref',
        'is_shared',
        'private_notes',
        'status',
    ];

    protected $casts = [
        'record_date' => 'date',
        'is_shared' => 'boolean',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id', 'id');
    }
}

