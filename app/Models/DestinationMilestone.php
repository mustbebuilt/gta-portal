<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DestinationMilestone extends Model {
 protected $table='destination_milestones';
 protected $guarded=['id'];
 protected function casts():array {return ['exit_on'=>'date','due_on'=>'date','retry_on'=>'date','version'=>'integer','months'=>'integer']; }
}
