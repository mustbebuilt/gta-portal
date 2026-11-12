<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportPlan extends Model
{
    protected $attributes = ['version' => 1, 'status' => 'Draft'];

    protected $fillable = ['apprentice_id', 'concern_ids', 'draft'];

    protected function casts(): array
    {
        return ['concern_ids' => 'array', 'draft' => 'array', 'active_plan' => 'array', 'history' => 'array', 'reviews' => 'array', 'activities' => 'array', 'files' => 'array', 'version' => 'integer'];
    }

    public function apprentice(): BelongsTo
    {
        return $this->belongsTo(Apprentice::class);
    }

    public function nextReview(): ?string
    {
        return (collect($this->reviews ?? [])->filter(fn ($r) => ($r['active_snapshot']['approved_at'] ?? null) === ($this->active_plan['approved_at'] ?? null))->last()['next'] ?? null) ?? ($this->active_plan['fields']['review'] ?? null);
    }
}
