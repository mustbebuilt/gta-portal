<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CareersPrompt extends Model
{
    protected $fillable=['apprentice_id','recipient_id','block','start_date','plan_hash','source_key'];

    protected function casts(): array
    {
        return ['recipient_id'=>'integer','apprentice_id'=>'integer','block'=>'integer','start_date'=>'date','seen_at'=>'datetime'];
    }
}
