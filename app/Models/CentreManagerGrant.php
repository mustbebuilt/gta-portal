<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CentreManagerGrant extends Model {
 protected $table='centre_manager_grants';protected $primaryKey='user_id';protected $guarded=[];protected $hidden=['details'];public $incrementing=false;
 protected function casts():array{return ['user_id'=>'integer','version'=>'integer','active'=>'boolean','ceo_locked'=>'boolean','updated_by'=>'integer','expires_on'=>'date','details'=>'encrypted:array'];}
}
