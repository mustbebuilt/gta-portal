<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AccountLifecycleProposal extends Model {protected $table='account_lifecycle_proposals';protected $guarded=[];protected $hidden=['details','approval','applied','proof','body'];public $incrementing=false;protected $keyType='string';protected function casts():array {return ['target_id'=>'integer','created_by'=>'integer','version'=>'integer','details'=>'encrypted:array','approval'=>'encrypted:array','applied'=>'encrypted:array'];}}
