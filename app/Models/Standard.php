<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Standard extends Model
{
    use HasFactory;

    protected $primaryKey = 'standard_id';

    protected $fillable = [
        'standard_code',
        'title',
        'level',
        'duration_months',
        'total_otj_target',
    ];

    protected $casts = [
        'level' => 'integer',
        'duration_months' => 'integer',
        'total_otj_target' => 'decimal:2',
    ];

    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class, 'standard_id', 'standard_id');
    }

    public function apprentices(): HasMany
    {
        return $this->hasMany(Apprentice::class, 'standard_id', 'standard_id');
    }
}

