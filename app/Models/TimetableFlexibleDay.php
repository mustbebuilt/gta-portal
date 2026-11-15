<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TimetableFlexibleDay extends Model {protected $table='timetable_flexible_days';protected $guarded=[];protected $hidden=['source'];
 protected function casts():array {return ['staff_id'=>'integer','owner_id'=>'integer','work_date'=>'date','version'=>'integer','source'=>'encrypted:array'];}
}
