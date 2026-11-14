<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DataImportBatch extends Model
{
    protected $guarded=['id'];
    protected $attributes=['state'=>'Preview','version'=>1];
    public function preparedBy() {return $this->belongsTo(User::class,'prepared_by');}
    public function appliedBy() {return $this->belongsTo(User::class,'applied_by');}
    protected function casts(): array {return ['rows'=>'array','version'=>'integer','prepared_by'=>'integer','applied_by'=>'integer','applied_at'=>'datetime'];}
}
