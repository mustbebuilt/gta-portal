<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewSignature extends Model
{
    use HasFactory;

    protected $fillable = [
        'review_id',
        'user_id',
        'party_role',
        'signatory_name',
        'signed_at',
        'ip_address',
        'document_snapshot_hash',
    ];

    protected $casts = [
        'signed_at' => 'datetime',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(ProgressReview::class, 'review_id', 'id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}

