<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class HistoricalOtjRecord extends Model
{
    protected $guarded=['id'];
    protected function casts(): array {return ['programme_identity'=>'array','history'=>'array','training_date'=>'date','withdrawn_at'=>'datetime','version'=>'integer','minutes'=>'integer','excluded_minutes'=>'integer'];}
}
