<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffDevelopmentTarget extends Model
{
    use HasFactory;

    protected $primaryKey = 'target_id';

    protected $fillable = [
        'user_id',
        'target',
        'measure',
        'reason',
        'support_provided',
        'deadline',
        'status',
    ];

    protected $casts = [
        'deadline' => 'date',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}

