<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PortalPrivacyState extends Model {protected $table='portal_privacy_state';protected $guarded=[];protected $hidden=['body','publication','proof','details'];protected function casts():array {return ['revision'=>'integer','enabled'=>'boolean','proof'=>'encrypted:array'];}}
