<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportConcern extends Model
{
    use HasFactory;

    protected $attributes = ['version' => 1, 'status' => 'New'];

    protected $fillable = ['apprentice_id', 'raised_by', 'initial_note'];

    protected function casts(): array
    {
        return ['action_due' => 'date', 'next_review' => 'date', 'acknowledged_at' => 'datetime', 'closed_at' => 'datetime', 'followups' => 'array', 'history' => 'array', 'version' => 'integer'];
    }

    public function apprentice(): BelongsTo
    {
        return $this->belongsTo(Apprentice::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
