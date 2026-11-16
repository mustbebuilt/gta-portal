<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CentreManagerAccessRequest extends Model {
 protected $table='centre_manager_access_requests';protected $guarded=[];protected $hidden=['details','approval','applied'];public $incrementing=false;protected $keyType='string';
 protected function casts():array{return ['created_by'=>'integer','target_user_id'=>'integer','version'=>'integer','details'=>'encrypted:array','approval'=>'encrypted:array','applied'=>'encrypted:array'];}
}
