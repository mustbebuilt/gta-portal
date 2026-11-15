<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ReviewAppointment extends Model {
 protected $table='review_appointments';public $incrementing=false;protected $keyType='string';protected $guarded=['id'];protected $hidden=['source','publication','closure','outcome','followup'];
 protected function casts():array {return ['source'=>'encrypted:array','publication'=>'encrypted:array','closure'=>'encrypted:array','outcome'=>'encrypted:array','followup'=>'encrypted:array','apprentice_id'=>'integer','created_by'=>'integer','version'=>'integer','followup_version'=>'integer','active_slot'=>'integer','starts_at'=>'datetime','ends_at'=>'datetime','published_at'=>'datetime'];}
 public function parties(){return $this->hasMany(ReviewAppointmentParty::class,'appointment_id');}
}
