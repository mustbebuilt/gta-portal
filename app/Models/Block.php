<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Block extends Model
{
    use HasFactory;

    protected $primaryKey = 'block_id';

    protected $fillable = [
        'standard_id',
        'block_num',
        'title',
        'primary_themes',
        'nominal_hours',
    ];

    protected $casts = [
        'block_num' => 'integer',
        'nominal_hours' => 'integer',
    ];

    public function standard(): BelongsTo
    {
        return $this->belongsTo(Standard::class, 'standard_id', 'standard_id');
    }
}

