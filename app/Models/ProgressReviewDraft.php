<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgressReviewDraft extends Model
{
    use HasFactory;

    protected $attributes = ['version' => 1, 'state' => 'Draft'];

    protected $fillable = ['apprentice_id', 'author_id', 'fields', 'source_snapshot'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'source_snapshot' => 'array', 'history' => 'array', 'version' => 'integer'];
    }

    public function apprentice(): BelongsTo
    {
        return $this->belongsTo(Apprentice::class);
    }
}
