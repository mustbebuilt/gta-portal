<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprenticeMandatoryQual extends Model
{
    use HasFactory;

    protected $fillable = [
        'apprentice_id',
        'qualification_name',
        'awarding_body',
        'status',
        'custom_start_date',
        'target_date',
        'achieved_date',
    ];

    protected $casts = [
        'custom_start_date' => 'date',
        'target_date' => 'date',
        'achieved_date' => 'date',
    ];

    public function apprentice(): BelongsTo
    {
        return $this->belongsTo(Apprentice::class, 'apprentice_id', 'apprentice_id');
    }
}

