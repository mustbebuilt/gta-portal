<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TimetableGovernanceRole extends Model {protected $table='timetable_governance_roles';protected $guarded=[];protected $hidden=['history'];public $incrementing=false;protected $primaryKey='user_id';
 protected function casts():array {return ['user_id'=>'integer','changed_by'=>'integer','version'=>'integer','restricted'=>'boolean','ceo_locked'=>'boolean','history'=>'encrypted:array'];}
}
