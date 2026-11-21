<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProgrammeOutcome extends Model
{
    use HasFactory;

    public const REQUIREMENT_CHOICES = ['Unknown', 'Yes', 'No'];

    protected $attributes = ['version' => 1];

    protected $fillable = ['apprentice_id', 'context'];

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('context->epa_closure->archived_at');
    }

    protected function casts(): array
    {
        return ['context' => 'array', 'history' => 'array', 'version' => 'integer'];
    }
}
