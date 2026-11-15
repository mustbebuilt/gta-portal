<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TimetableGroupBrief extends Model {protected $table='timetable_group_briefs';protected $guarded=[];protected $hidden=['details'];public $incrementing=false;protected $primaryKey='group_id';
 protected function casts():array {return ['group_id'=>'integer','updated_by'=>'integer','version'=>'integer','details'=>'encrypted:array'];}
}
