<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ReviewAppointmentParty extends Model {
 protected $table='review_appointment_parties';protected $guarded=['id'];protected $hidden=['proof'];public $timestamps=false;
 protected function casts():array {return ['proof'=>'encrypted:array','user_id'=>'integer','appointment_version'=>'integer','response_version'=>'integer','responded_at'=>'datetime'];}
}
