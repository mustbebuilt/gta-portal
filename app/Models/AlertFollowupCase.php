<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AlertFollowupCase extends Model {
 protected $guarded=['id'];protected $hidden=['proposal','resolution'];
 protected function casts():array {return ['proposal'=>'encrypted:array','resolution'=>'encrypted:array','version'=>'integer','due_on'=>'date','closed_at'=>'datetime'];}
}
