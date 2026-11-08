<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprenticeTaskUpload extends Model
{
    use HasFactory;

    protected $primaryKey = 'atu_id';

    protected $fillable = [
        'at_id',
        'apprentice_id',
        'original_filename',
        'stored_path',
        'mime_type',
        'file_size_bytes',
        'status',
        'superseded_at',
    ];

    protected $casts = [
        'file_size_bytes' => 'integer',
        'superseded_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(ApprenticeTask::class, 'at_id', 'at_id');
    }

    public function apprentice(): BelongsTo
    {
        return $this->belongsTo(Apprentice::class, 'apprentice_id', 'apprentice_id');
    }
}

