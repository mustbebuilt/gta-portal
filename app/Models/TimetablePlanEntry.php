<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TimetablePlanEntry extends Model {
 protected $table='timetable_plan_entries';protected $guarded=[];protected $hidden=['link_proof'];public $timestamps=false;
 protected function casts():array {return ['work_date'=>'date','staff_id'=>'integer','room_id'=>'integer','group_id'=>'integer','headcount'=>'integer','training_session_id'=>'integer','link_proof'=>'encrypted:array'];}
}
