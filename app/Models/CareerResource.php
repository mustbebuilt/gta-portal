<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CareerResource extends Model
{
    use HasFactory;

    protected $primaryKey = 'resource_id';

    protected $fillable = [
        'title',
        'category',
        'description',
        'link_url',
        'target_stage',
    ];
}

