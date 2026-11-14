<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployerApprenticeGrant extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'employer_version' => 'integer', 'version' => 'integer', 'history' => 'array'];
    }
}
