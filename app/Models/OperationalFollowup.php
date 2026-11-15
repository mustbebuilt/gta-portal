<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OperationalFollowup extends Model {protected $table='operational_followups';protected $guarded=[];protected $hidden=['details','approval','applied','proof','body'];public $incrementing=false;protected $keyType='string';protected function casts():array {return ['apprentice_id'=>'integer','owner_id'=>'integer','version'=>'integer','details'=>'encrypted:array'];}}
