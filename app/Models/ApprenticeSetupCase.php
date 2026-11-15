<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ApprenticeSetupCase extends Model {
 protected $table='apprentice_setup_cases';public $incrementing=false;protected $keyType='string';protected $guarded=['id'];protected $hidden=['details','proof'];
 protected function casts():array {return ['details'=>'encrypted:array','proof'=>'encrypted:array','version'=>'integer','apprentice_id'=>'integer','owner_id'=>'integer','decided_by'=>'integer','decided_at'=>'datetime','due_on'=>'date'];}
}
