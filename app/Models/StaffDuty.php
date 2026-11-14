<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffDuty extends Model
{
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return ['user_id'=>'integer','careers_adviser'=>'boolean','mentor'=>'boolean','maths_tutor'=>'boolean','english_tutor'=>'boolean','version'=>'integer','history'=>'array'];
    }
}
