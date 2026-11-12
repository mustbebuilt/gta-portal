<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskEvidence extends Model
{
    protected $table = 'task_evidence';

    protected $fillable = ['learning_task_id', 'uploaded_by', 'path', 'name', 'mime', 'sha256', 'bytes', 'kind'];
}
