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
    ];

    protected $casts = [
        'start_date' => 'date',
        'planned_end_date' => 'date',
        'actual_end_date' => 'date',
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
}

