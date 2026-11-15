<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AccountLifecycleState extends Model {protected $table='account_lifecycle_states';protected $guarded=[];protected $hidden=['details','approval','applied','proof','body'];public $incrementing=false;protected $primaryKey='user_id';protected function casts():array {return ['user_id'=>'integer','version'=>'integer','review_on'=>'date','proof'=>'encrypted:array'];}}
