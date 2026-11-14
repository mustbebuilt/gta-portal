<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportPlanReminder extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['support_plan_id' => 'integer', 'recipient_id' => 'integer', 'source' => 'array', 'seen_at' => 'datetime', 'dismissed_at' => 'datetime'];
    }
}
