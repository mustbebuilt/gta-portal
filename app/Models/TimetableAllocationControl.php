<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TimetableAllocationControl extends Model {protected $table='timetable_allocation_controls';protected $guarded=[];protected $hidden=['details'];public $incrementing=false;protected $primaryKey='plan_id';protected $keyType='string';
 protected function casts():array {return ['version'=>'integer','changed_by'=>'integer','restricted'=>'boolean','ceo_locked'=>'boolean','details'=>'encrypted:array'];}
}
