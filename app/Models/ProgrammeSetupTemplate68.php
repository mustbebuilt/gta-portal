<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ProgrammeSetupTemplate68 extends Model {
 protected $table='programme_setup_templates68';protected $guarded=[];
 protected function casts():array{return ['template'=>'array','version'=>'integer','active'=>'boolean','programme_id'=>'integer'];}
}
