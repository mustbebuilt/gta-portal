<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Programme extends Model
{
    use HasFactory;

    protected $attributes = ['active' => true, 'record_version' => 1];

    protected $fillable = ['standard_code', 'version', 'name', 'level', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'record_version' => 'integer'];
    }
}
