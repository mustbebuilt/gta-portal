<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DestinationMilestoneCheck extends Model {
 protected $table='destination_milestone_checks';
 protected $guarded=['id'];
 protected function casts():array {return ['observed_on'=>'date','continuous_from'=>'date','retry_on'=>'date','source'=>'encrypted']; }
}
