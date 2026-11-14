<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarkingReminder extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'seen_at' => 'datetime', 'submission_version' => 'integer', 'recipient_id' => 'integer', 'learning_task_id' => 'integer'];
    }
}
