<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class StagingAcceptanceCheck extends Model {
 protected $table='staging_acceptance_checks';protected $guarded=['id'];protected $hidden=['details','review_note','review_proof'];
 protected function casts():array {return ['details'=>'encrypted:array','review_note'=>'encrypted','review_proof'=>'encrypted:array','version'=>'integer','tested_at'=>'datetime','reviewed_at'=>'datetime']; }
}
