<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class HistoricalCohort extends Model {
 protected $table='historical_cohorts';protected $guarded=[];protected $hidden=['details','proof'];public $incrementing=false;protected $keyType='string';
 protected function casts():array{return ['created_by'=>'integer','version'=>'integer','details'=>'encrypted:array','proof'=>'encrypted:array','approved_at'=>'datetime'];}
}
