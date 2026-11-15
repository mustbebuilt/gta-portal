<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TimetableStaffSection extends Model {protected $table='timetable_staff_sections';protected $guarded=[];protected $hidden=['details'];public $incrementing=false;protected $primaryKey='user_id';
 protected function casts():array {return ['user_id'=>'integer','version'=>'integer','changed_by'=>'integer','details'=>'encrypted:array'];}
}
