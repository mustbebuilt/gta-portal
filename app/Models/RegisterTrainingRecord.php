<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegisterTrainingRecord extends Model
{
    protected $primaryKey = 'training_session_id';

    public $incrementing = false;

    protected $guarded = ['*'];

    protected $hidden = ['details', 'history'];

    protected function casts(): array
    {
        return [
            'training_session_id' => 'integer',
            'recorded_by' => 'integer',
            'register_version' => 'integer',
            'version' => 'integer',
            'details' => 'encrypted:array',
            'history' => 'encrypted:array',
        ];
    }
}
