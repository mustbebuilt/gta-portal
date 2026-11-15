<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TimetablePlannerAccess extends Model {
 protected $table='timetable_planner_access';protected $guarded=[];protected $hidden=['history'];public $incrementing=false;protected $primaryKey='user_id';
 protected function casts():array {return ['history'=>'encrypted:array','user_id'=>'integer','granted_by'=>'integer','active'=>'boolean','version'=>'integer'];}
}
