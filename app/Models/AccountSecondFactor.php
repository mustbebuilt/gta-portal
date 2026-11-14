<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountSecondFactor extends Model
{
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $guarded = [];
    protected $hidden = ['secret', 'recovery_hashes', 'setup_binding'];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'setup_expires_at' => 'datetime', 'confirmed_at' => 'datetime',
            'last_step' => 'integer', 'recovery_hashes' => 'array'];
    }
}
