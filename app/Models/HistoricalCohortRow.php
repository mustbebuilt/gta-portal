<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class HistoricalCohortRow extends Model {
 protected $table='historical_cohort_rows';protected $guarded=[];protected $hidden=['details'];public $incrementing=false;protected $keyType='string';
 protected function casts():array{return ['apprentice_id'=>'integer','historical_tutor_id'=>'integer','version'=>'integer','details'=>'encrypted:array'];}
}
