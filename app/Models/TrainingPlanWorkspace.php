<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingPlanWorkspace extends Model
{
    use HasFactory;

    protected $attributes = ['version' => 1];

    protected $fillable = ['apprentice_id', 'source_hash', 'proposal', 'rpl'];

    protected function casts(): array
    {
        return ['proposal' => 'array', 'rpl' => 'array', 'reviews' => 'array', 'history' => 'array', 'version' => 'integer'];
    }

    public function apprentice(): BelongsTo
    {
        return $this->belongsTo(Apprentice::class);
    }
}
