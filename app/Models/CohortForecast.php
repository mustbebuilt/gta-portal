<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CohortForecast extends Model {protected $table='cohort_forecasts';protected $guarded=[];protected $hidden=['details','approval','applied','proof','body'];public $incrementing=false;protected $primaryKey='apprentice_id';protected function casts():array {return ['apprentice_id'=>'integer','version'=>'integer','details'=>'encrypted:array'];}}
