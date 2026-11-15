<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AlertReceiptRecord extends Model {
 public $incrementing=false;protected $keyType='string';public $timestamps=false;protected $guarded=['id'];protected $hidden=['source_reference'];
 protected function casts():array {return ['source_reference'=>'encrypted','occurred_at'=>'datetime','withdrawn_at'=>'datetime','created_at'=>'datetime'];}
}
