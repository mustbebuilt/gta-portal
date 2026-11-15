<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PortalPrivacyAcknowledgement extends Model {protected $table='portal_privacy_acknowledgements';protected $guarded=[];protected $hidden=['body','publication','proof','details'];public $timestamps=false;protected function casts():array {return ['user_id'=>'integer','proof'=>'encrypted:array','acknowledged_at'=>'datetime'];}}
