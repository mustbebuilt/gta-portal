<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SourceReconciliation extends Model
{
    protected $guarded=['id'];
    protected $attributes=['state'=>'Submitted','version'=>1];
    protected function casts(): array {return ['snapshot'=>'array','version'=>'integer','apprentice_id'=>'integer','author_id'=>'integer','reviewer_id'=>'integer','reviewed_at'=>'datetime'];}
}
