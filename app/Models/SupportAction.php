<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportAction extends Model
{
    use HasFactory;

    protected $fillable = [
        'support_plan_id',
        'action_description',
        'responsible_role',
        'target_date',
        'completed',
        'completed_at',
    ];

    protected $casts = [
        'target_date' => 'date',
        'completed' => 'boolean',
        'completed_at' => 'datetime',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SupportPlan::class, 'support_plan_id', 'id');
    }
}

