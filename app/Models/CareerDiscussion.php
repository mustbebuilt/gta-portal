<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareerDiscussion extends Model
{
    use HasFactory;

    protected $primaryKey = 'discussion_id';

    protected $fillable = [
        'apprentice_id',
        'adviser_id',
        'discussion_type',
        'discussion_date',
        'goals',
        'options_discussed',
        'advice_given',
        'outcome',
        'referral_to',
        'next_steps',
    ];

    protected $casts = [
        'discussion_date' => 'date',
    ];

    public function apprentice(): BelongsTo
    {
        return $this->belongsTo(Apprentice::class, 'apprentice_id', 'apprentice_id');
    }

    public function adviser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adviser_id', 'id');
    }
}

