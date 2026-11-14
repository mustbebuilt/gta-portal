<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReviewSigningAuthority extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'review_signing_authorities';

    protected $guarded = [];

    protected $attributes = ['active' => true, 'version' => 1];

    protected $hidden = ['authority_reference', 'history'];

    protected function casts(): array
    {
        return ['authority_reference' => 'encrypted', 'history' => 'encrypted:array', 'expires_on' => 'date', 'active' => 'boolean', 'version' => 'integer'];
    }
}
