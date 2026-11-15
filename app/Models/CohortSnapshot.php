<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CohortSnapshot extends Model {protected $table='cohort_snapshots';protected $guarded=[];protected $hidden=['details','approval','applied','proof','body'];public $incrementing=false;protected $keyType='string';protected function casts():array {return ['created_by'=>'integer','body'=>'encrypted:array'];}}
