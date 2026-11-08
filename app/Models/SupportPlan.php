<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'apprentice_id',
        'plan_number',
        'plan_type',
        'status',
        'primary_support_need',
        'authorized_teaching_adjustments',
        'confidential_case_notes',
        'referral_date',
        'plan_lead_user_id',
        'info_sharing_consent',
    ];

    protected $casts = [
        'referral_date' => 'date',
        'info_sharing_consent' => 'boolean',
        'plan_number' => 'integer',
    ];

    public function apprentice(): BelongsTo
    {
        return $this->belongsTo(Apprentice::class, 'apprentice_id', 'apprentice_id');
    }

    public function planLead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'plan_lead_user_id', 'id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(SupportAction::class, 'support_plan_id', 'id');
    }
}

