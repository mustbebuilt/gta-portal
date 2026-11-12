<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Employer extends Model
{
    use HasFactory;

    protected $attributes = ['active' => true, 'record_version' => 1];

    protected $fillable = ['reference', 'name', 'contact_name', 'contact_email', 'contact_phone', 'size_band', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'record_version' => 'integer'];
    }
}
