<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class EntryContextEvent extends Model {
 protected $table='entry_context_events';
 protected $guarded=['id'];
 protected function casts():array {return ['payload'=>'encrypted:array','effective_on'=>'date','revision'=>'integer']; }
}
