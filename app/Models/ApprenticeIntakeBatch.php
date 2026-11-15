<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ApprenticeIntakeBatch extends Model {
 protected $table='apprentice_intake_batches';public $incrementing=false;protected $keyType='string';protected $guarded=['id'];protected $hidden=['source','receipt','review_note'];
 protected function casts():array{return ['source'=>'encrypted:array','receipt'=>'encrypted:array','review_note'=>'encrypted','version'=>'integer','created_by'=>'integer','reviewed_by'=>'integer','expires_at'=>'datetime','reviewed_at'=>'datetime'];}
}
