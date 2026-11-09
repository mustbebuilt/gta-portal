<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TutorGroup extends Model
{
    use HasFactory;

    protected $primaryKey = 'tutor_group_id';

    protected $fillable = [
        'group_name',
        'group_code',
        'tutor_user_id',
    ];

    public function tutor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tutor_user_id', 'id');
    }

    public function apprentices(): BelongsToMany
    {
        return $this->belongsToMany(
            Apprentice::class,
            'apprentice_tutor_groups',
            'tutor_group_id',
            'apprentice_id'
        )->withTimestamps();
    }
}

