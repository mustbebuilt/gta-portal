<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PortalPrivacyCase extends Model {protected $table='portal_privacy_cases';protected $guarded=[];protected $hidden=['body','publication','proof','details'];public $incrementing=false;protected $keyType='string';protected function casts():array {return ['user_id'=>'integer','open_user_id'=>'integer','version'=>'integer','review_on'=>'date','details'=>'encrypted:array'];}}
