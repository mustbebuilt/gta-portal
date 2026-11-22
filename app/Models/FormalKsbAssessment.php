<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormalKsbAssessment extends Model
{
    protected $fillable = ['apprentice_id', 'recorded_by', 'curriculum_key', 'code', 'outcome', 'assessed_on', 'evidence_reference'];

    protected function casts(): array
    {
        return ['evidence_reference'=>'encrypted', 'assessed_on'=>'date'];
    }
}
