<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortalQuestionnaireSubmission extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $attributes = ['state' => 'Submitted', 'version' => 1];

    protected $hidden = ['answer_payload', 'private_note', 'shared_note', 'payload_hash', 'followup_history'];

    protected function casts(): array
    {
        return ['answer_payload' => 'encrypted:array', 'private_note' => 'encrypted', 'shared_note' => 'encrypted', 'followup_history' => 'encrypted:array', 'followup_resolved_at' => 'datetime', 'checked_at' => 'datetime', 'submitted_at' => 'datetime', 'revision' => 'integer', 'version' => 'integer'];
    }
}
