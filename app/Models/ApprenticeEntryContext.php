<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ApprenticeEntryContext extends Model {
 protected $table='apprentice_entry_contexts';
 protected $guarded=['id'];
 protected function casts():array {return ['employment_start_date'=>'date','confirmed_on'=>'date','version'=>'integer']; }
}
