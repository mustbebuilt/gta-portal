<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseStudy extends Model
{
    use HasFactory;

    protected $fillable = [
        'author_user_id',
        'title',
        'category',
        'challenge',
        'starting_point',
        'response',
        'before_state',
        'before_date',
        'after_state',
        'after_date',
        'evidence',
        'outcome',
        'status',
        'approved_by_user_id',
        'approved_at',
    ];

    protected $casts = [
        'before_date' => 'date',
        'after_date' => 'date',
        'approved_at' => 'datetime',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id', 'id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id', 'id');
    }
}

