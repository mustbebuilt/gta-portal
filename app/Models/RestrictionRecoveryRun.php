<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class RestrictionRecoveryRun extends Model {
 protected $table='restriction_recovery_runs';protected $guarded=[];protected $hidden=['details','approval','applied'];public $incrementing=false;protected $keyType='string';
 protected function casts():array{return ['created_by'=>'integer','version'=>'integer','details'=>'encrypted:array','approval'=>'encrypted:array','applied'=>'encrypted:array'];}
}
