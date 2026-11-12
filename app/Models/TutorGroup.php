<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TutorGroup extends Model
{
    use HasFactory;

    protected $attributes = ['active' => true, 'record_version' => 1];

    protected $fillable = ['reference', 'name', 'programme_id', 'tutor_id', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'record_version' => 'integer'];
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    public function tutor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tutor_id');
    }
}
