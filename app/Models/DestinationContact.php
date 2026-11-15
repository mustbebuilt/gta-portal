<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DestinationContact extends Model {
 protected $guarded=['id'];protected $hidden=['detail','source','void_reason'];
 protected function casts():array {return ['detail'=>'encrypted:array','source'=>'encrypted:array','void_reason'=>'encrypted','contacted_on'=>'date','next_followup_on'=>'date','voided_at'=>'datetime','version'=>'integer'];}
}
