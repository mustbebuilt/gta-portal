<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortalQuestionnaireForm extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $attributes = ['state' => 'Draft', 'version' => 1];

    protected function casts(): array
    {
        return ['questions' => 'array', 'revision' => 'integer', 'version' => 'integer', 'published_at' => 'datetime'];
    }
}
