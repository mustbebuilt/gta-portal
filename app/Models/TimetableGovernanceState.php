<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TimetableGovernanceState extends Model {protected $table='timetable_governance_state';protected $guarded=[];protected $hidden=['details'];
 protected function casts():array {return ['revision'=>'integer','editing_paused'=>'boolean','details'=>'encrypted:array'];}
}
