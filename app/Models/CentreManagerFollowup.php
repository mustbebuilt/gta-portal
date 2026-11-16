<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CentreManagerFollowup extends Model {
 protected $table='centre_manager_followups';protected $guarded=[];protected $hidden=['details'];public $incrementing=false;protected $keyType='string';
 public function apprentice(){return $this->belongsTo(Apprentice::class);}
 public function owner(){return $this->belongsTo(User::class,'owner_id');}
 protected function casts():array{return ['apprentice_id'=>'integer','created_by'=>'integer','owner_id'=>'integer','version'=>'integer','grant_version'=>'integer','due_on'=>'date','details'=>'encrypted:array'];}
}
