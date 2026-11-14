<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortalQuestionnaireAssignment extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $attributes = ['state' => 'Assigned', 'version' => 1, 'submission_count' => 0];

    protected $hidden = ['draft_answers'];

    protected function casts(): array
    {
        return ['draft_answers' => 'encrypted:array', 'due_date' => 'date', 'submitted_at' => 'datetime', 'submission_count' => 'integer', 'version' => 'integer'];
    }

    public function form()
    {
        return $this->belongsTo(PortalQuestionnaireForm::class, 'form_id');
    }

    public function apprentice()
    {
        return $this->belongsTo(Apprentice::class);
    }

    public function submissions()
    {
        return $this->hasMany(PortalQuestionnaireSubmission::class, 'assignment_id');
    }
}
