<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ApprenticeMobileChallenge extends Model {
 public $incrementing=false;protected $keyType='string';protected $guarded=[];
 protected $hidden=['code_hash','phone_hash','session_hash','config_hash','provider_id'];
 protected function casts():array {return ['preference_revision'=>'integer','auth_version'=>'integer','attempts'=>'integer','expires_at'=>'datetime'];}
}
