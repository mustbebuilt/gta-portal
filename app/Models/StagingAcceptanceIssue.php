<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class StagingAcceptanceIssue extends Model {
 protected $table='staging_acceptance_issues';protected $guarded=['id'];protected $hidden=['details','proposal','review_proof'];
 public $incrementing=false;protected $keyType='string';
 protected function casts():array {return ['details'=>'encrypted:array','proposal'=>'encrypted:array','review_proof'=>'encrypted:array','version'=>'integer','due_on'=>'date','reviewed_at'=>'datetime']; }
}
