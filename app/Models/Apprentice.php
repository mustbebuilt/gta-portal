<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Apprentice extends Model
{
    use HasFactory;

    protected $table = 'apprentice';
    protected $primaryKey = 'apprentice_id';

    protected $fillable = [
        'user_id',
        'employer_id',
        'trainer_id',
        'standard_id',
        'start_date',
        'planned_end_date',
        'actual_end_date',
        'otj_target_hours',
        'otj_hours_req_to_date',
        'otj_hours_completed',
        'status',
        'achievement_status',
        'achievement_date',
        'withdrawal_reason',
        'withdrawal_reason_basis',
        'apprentice_voice',
        'outcome_source_ref',
        'destination_type',
        'destination_date',
        'destination_basis',
        'destination_ref',
        'employer_headcount_band',
        'headcount_reference_date',
        'additional_needs_recorded',
        'ehcp_recorded',
        'context_source_ref',
    ];

    protected $casts = [
        'start_date' => 'date',
        'planned_end_date' => 'date',
        'actual_end_date' => 'date',
        'achievement_date' => 'date',
        'destination_date' => 'date',
        'headcount_reference_date' => 'date',
        'otj_target_hours' => 'decimal:2',
        'otj_hours_req_to_date' => 'decimal:2',
        'otj_hours_completed' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trainer_id', 'id');
    }

    public function employer(): BelongsTo
    {
        return $this->belongsTo(Employer::class, 'employer_id', 'employer_id');
    }

    public function standard(): BelongsTo
    {
        return $this->belongsTo(Standard::class, 'standard_id', 'standard_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ApprenticeTask::class, 'apprentice_id', 'apprentice_id');
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class, 'apprentice_id', 'apprentice_id');
    }

    public function progressReviews(): HasMany
    {
        return $this->hasMany(ProgressReview::class, 'apprentice_id', 'apprentice_id');
    }

    public function functionalSkills(): HasMany
    {
        return $this->hasMany(ApprenticeFunctionalSkill::class, 'apprentice_id', 'apprentice_id');
    }

    public function mandatoryQualifications(): HasMany
    {
        return $this->hasMany(ApprenticeMandatoryQual::class, 'apprentice_id', 'apprentice_id');
    }

    public function actionPlans(): HasMany
    {
        return $this->hasMany(ActionPlan::class, 'apprentice_id', 'apprentice_id');
    }

    public function supportPlans(): HasMany
    {
        return $this->hasMany(SupportPlan::class, 'apprentice_id', 'apprentice_id');
    }

    public function careerDiscussions(): HasMany
    {
        return $this->hasMany(CareerDiscussion::class, 'apprentice_id', 'apprentice_id');
    }

    public function tutorGroups(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(TutorGroup::class, 'apprentice_tutor_groups', 'apprentice_id', 'tutor_group_id');
    }
}

