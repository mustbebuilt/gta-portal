<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AlertReceiptBatch extends Model {
 public $incrementing=false;protected $keyType='string';protected $guarded=['id'];protected $hidden=['rows','source_reference','review_note'];
 protected function casts():array {return ['rows'=>'encrypted:array','source_reference'=>'encrypted','review_note'=>'encrypted','version'=>'integer','checked_at'=>'datetime','withdrawn_at'=>'datetime'];}
}
