<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class RetentionAdminAssignment extends Model {
 protected $table='retention_admin_assignments';
 protected $guarded=['id'];
 protected function casts():array {return ['due_on'=>'date','version'=>'integer']; }
}
