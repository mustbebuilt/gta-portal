<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ApprenticeSetupDraft68 extends Model {
 protected $attributes=['version'=>1,'state'=>'Draft'];
 protected $table='apprentice_setup_drafts68';public $incrementing=false;protected $keyType='string';protected $guarded=[];protected $hidden=['payload','history'];
 protected function casts():array{return ['payload'=>'encrypted:array','history'=>'encrypted:array','version'=>'integer','owner_id'=>'integer','apprentice_id'=>'integer'];}
}
