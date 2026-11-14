<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SignedReviewRecord extends Model
{
    protected $guarded=['id'];
    protected $attributes=['version'=>1];
    protected function casts(): array
    {
        return ['apprentice_id'=>'integer','progress_review_draft_id'=>'integer','snapshot'=>'array','file'=>'array','history'=>'array','meeting_date'=>'date','checked_at'=>'datetime','withdrawn_at'=>'datetime','version'=>'integer','prepared_by'=>'integer','checked_by'=>'integer','review_version'=>'integer'];
    }
}
