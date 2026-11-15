<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TimetableChangeBatch extends Model {
 protected $table='timetable_change_batches';public $incrementing=false;protected $keyType='string';protected $guarded=['id'];protected $hidden=['source','details','receipt','proof','review_note'];
 protected function casts():array {return ['source'=>'encrypted:array','details'=>'encrypted:array','receipt'=>'encrypted:array','proof'=>'encrypted:array','review_note'=>'encrypted','version'=>'integer','created_by'=>'integer','tutor_group_id'=>'integer','training_session_id'=>'integer','owner_id'=>'integer','reviewed_by'=>'integer','decided_by'=>'integer','reviewed_at'=>'datetime','decided_at'=>'datetime','expires_at'=>'datetime','due_on'=>'date'];}
}
