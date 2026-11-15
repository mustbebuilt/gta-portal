<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TimetableCoverAllocation extends Model {protected $table='timetable_cover_allocations';protected $guarded=[];protected $hidden=['source','check_proof','publication','closure'];public $incrementing=false;protected $keyType='string';
 protected function casts():array {return ['entry_id'=>'integer','active_entry_id'=>'integer','original_staff_id'=>'integer','cover_staff_id'=>'integer','flexible_day_id'=>'integer','created_by'=>'integer','version'=>'integer','source'=>'encrypted:array','check_proof'=>'encrypted:array','publication'=>'encrypted:array','closure'=>'encrypted:array'];}
}
