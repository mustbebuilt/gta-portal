<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class WorkplaceSupportShare extends Model {
 public $incrementing=false;protected $keyType='string';protected $guarded=['id'];
 protected $hidden=['payload','source','permission'];
 protected function casts():array {return ['payload'=>'encrypted:array','source'=>'encrypted:array','permission'=>'encrypted:array','version'=>'integer','expires_at'=>'datetime','checked_at'=>'datetime','decided_at'=>'datetime','acknowledged_at'=>'datetime','withdrawn_at'=>'datetime'];}
}
