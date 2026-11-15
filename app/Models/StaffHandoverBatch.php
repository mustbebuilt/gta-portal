<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class StaffHandoverBatch extends Model {
 protected $table='staff_handover_batches';public $incrementing=false;protected $keyType='string';protected $guarded=['id'];protected $hidden=['source','receipt','review_note'];
 protected function casts():array {return ['source'=>'encrypted:array','receipt'=>'encrypted:array','review_note'=>'encrypted','version'=>'integer','created_by'=>'integer','target_user_id'=>'integer','from_user_id'=>'integer','reviewed_by'=>'integer','reviewed_at'=>'datetime','expires_at'=>'datetime'];}
}
