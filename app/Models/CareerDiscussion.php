<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareerDiscussion extends Model
{
    use HasFactory;

    protected $attributes = ['version' => 1];

    protected $fillable = ['apprentice_id', 'adviser_id', 'recorded_by', 'type', 'discussion_date', 'goals', 'options', 'advice', 'outcome', 'next_step', 'referral'];

    protected function casts(): array
    {
        return ['discussion_date' => 'date', 'actions' => 'array', 'followups' => 'array', 'history' => 'array', 'version' => 'integer'];
    }

    public function apprentice(): BelongsTo
    {
        return $this->belongsTo(Apprentice::class);
    }

    public function adviser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adviser_id');
    }
}
