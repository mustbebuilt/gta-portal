<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ReviewAppointmentResponse extends Model {
 protected $table='review_appointment_responses';protected $guarded=['id'];protected $hidden=['proof'];public $timestamps=false;
 protected function casts():array {return ['proof'=>'encrypted:array','user_id'=>'integer','appointment_version'=>'integer','response_version'=>'integer','responded_at'=>'datetime'];}
}
