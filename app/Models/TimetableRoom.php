<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TimetableRoom extends Model {
 protected $table='timetable_rooms';protected $guarded=[];protected $hidden=['history'];
 protected function casts():array {return ['uses'=>'encrypted:array','history'=>'encrypted:array','active'=>'boolean','capacity'=>'integer','version'=>'integer','created_by'=>'integer'];}
}
