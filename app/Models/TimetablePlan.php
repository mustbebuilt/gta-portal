<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TimetablePlan extends Model {
 protected $table='timetable_plans';protected $guarded=[];protected $hidden=['source','check_proof','publication','withdrawal'];public $incrementing=false;protected $keyType='string';
 protected function casts():array {return ['source'=>'encrypted:array','check_proof'=>'encrypted:array','publication'=>'encrypted:array','withdrawal'=>'encrypted:array','version'=>'integer','created_by'=>'integer','published_at'=>'datetime'];}
}
