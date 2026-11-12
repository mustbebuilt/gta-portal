<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeachingInstruction extends Model
{
    protected $attributes = ['version' => 1, 'draft_revision' => 1];

    protected $fillable = ['apprentice_id', 'draft_text', 'private_note', 'recipient_ids'];

    protected function casts(): array
    {
        return ['recipient_ids' => 'array', 'release' => 'array', 'acknowledgements' => 'array', 'history' => 'array', 'version' => 'integer', 'draft_revision' => 'integer'];
    }

    public function hasUnreleasedDraft(): bool
    {
        return ! $this->release || ($this->release['draft_revision'] ?? 1) !== $this->draft_revision;
    }

    public function currentRelease(): ?array
    {
        return $this->release && empty($this->release['withdrawn_at']) ? $this->release : null;
    }

    public function apprentice(): BelongsTo
    {
        return $this->belongsTo(Apprentice::class);
    }
}
