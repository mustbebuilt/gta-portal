<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HelpNarrationRecord extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $attributes = ['state' => 'Uploaded', 'version' => 1];

    protected $hidden = ['file', 'source_reference', 'history', 'publication', 'binding', 'publication_hash'];

    protected function casts(): array
    {
        return ['file' => 'encrypted:array', 'source_reference' => 'encrypted', 'history' => 'encrypted:array', 'publication' => 'encrypted:array', 'reviewed_at' => 'datetime', 'version' => 'integer'];
    }
}
