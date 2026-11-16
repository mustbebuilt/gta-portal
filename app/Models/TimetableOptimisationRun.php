<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TimetableOptimisationRun extends Model {
 protected $table='timetable_optimisation_runs';protected $guarded=[];protected $hidden=['details','receipt'];public $incrementing=false;protected $keyType='string';
 protected function casts():array{return ['created_by'=>'integer','version'=>'integer','details'=>'encrypted:array','receipt'=>'encrypted:array'];}
}
