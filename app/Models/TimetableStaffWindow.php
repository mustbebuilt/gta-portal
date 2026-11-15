<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TimetableStaffWindow extends Model {
 protected $table='timetable_staff_windows';protected $guarded=[];protected $hidden=['proof'];
 protected function casts():array {return ['proof'=>'encrypted:array','user_id'=>'integer','updated_by'=>'integer','work_date'=>'date','working'=>'boolean','version'=>'integer'];}
}
