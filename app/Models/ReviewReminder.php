<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReviewReminder extends Model
{
    protected $fillable=['apprentice_id','signed_review_record_id','recipient_id','source','source_key'];

    protected function casts(): array
    {
        return ['apprentice_id'=>'integer','signed_review_record_id'=>'integer','recipient_id'=>'integer','source'=>'array','seen_at'=>'datetime'];
    }
}
