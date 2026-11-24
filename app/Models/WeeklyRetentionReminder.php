<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklyRetentionReminder extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id'];

    public function check(): BelongsTo
    {
        return $this->belongsTo(WeeklyRetentionCheck::class, 'weekly_retention_check_id');
    }
}
