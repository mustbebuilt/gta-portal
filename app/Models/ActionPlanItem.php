<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActionPlanItem extends Model
{
    use HasFactory;

    protected $primaryKey = 'action_item_id';

    protected $fillable = [
        'action_plan_id',
        'action_description',
        'owner_role',
        'target_date',
        'status',
        'completed_at',
    ];

    protected $casts = [
        'target_date' => 'date',
        'completed_at' => 'datetime',
    ];

    /**
     * Preserved Rule from RULES_AND_PERMISSIONS.txt:
     * Overdue actions are Red; Due today is not overdue; 14-day upcoming action alert window.
     */
    public function getIsOverdueAttribute(): bool
    {
        if ($this->status === 'Completed') {
            return false;
        }

        return $this->target_date->lt(Carbon::today());
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ActionPlan::class, 'action_plan_id', 'action_plan_id');
    }
}

