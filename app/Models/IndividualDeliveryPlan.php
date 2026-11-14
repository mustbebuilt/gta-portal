<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IndividualDeliveryPlan extends Model
{
    protected $attributes = ['version' => 1, 'active' => false];

    protected $fillable = ['apprentice_id', 'draft', 'history'];

    protected function casts(): array
    {
        return ['draft' => 'array', 'approved' => 'array', 'history' => 'array', 'active' => 'boolean', 'version' => 'integer'];
    }
}
