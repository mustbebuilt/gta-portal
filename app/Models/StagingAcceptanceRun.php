<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class StagingAcceptanceRun extends Model {
 protected $table='staging_acceptance_runs';protected $guarded=['id'];protected $hidden=['details','source_snapshot','receipt'];
 public $incrementing=false;protected $keyType='string';
 protected function casts():array {return ['details'=>'encrypted:array','source_snapshot'=>'encrypted:array','receipt'=>'encrypted:array','version'=>'integer','accepted_at'=>'datetime']; }
}
