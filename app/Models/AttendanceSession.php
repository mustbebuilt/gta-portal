<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceSession extends Model
{
    use HasFactory;

    protected $primaryKey = 'attendance_session_id';

    protected $fillable = [
        'tutor_group_id',
        'created_by_user_id',
        'session_datetime',
        'topic',
    ];

    protected $casts = [
        'session_datetime' => 'datetime',
    ];

    public function tutorGroup(): BelongsTo
    {
        return $this->belongsTo(TutorGroup::class, 'tutor_group_id', 'tutor_group_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id', 'id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class, 'attendance_session_id', 'attendance_session_id');
    }
}

