<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PortalPrivacyNotice extends Model {protected $table='portal_privacy_notices';protected $guarded=[];protected $hidden=['body','publication','proof','details'];public $incrementing=false;protected $keyType='string';protected function casts():array {return ['created_by'=>'integer','body'=>'encrypted:array','publication'=>'encrypted:array'];}}
