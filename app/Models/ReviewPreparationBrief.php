<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReviewPreparationBrief extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['period_from' => 'date', 'period_to' => 'date', 'source' => 'array', 'suggested' => 'array', 'applied' => 'array', 'applied_at' => 'datetime', 'applied_review_version' => 'integer'];
    }
}
