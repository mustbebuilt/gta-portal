<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DestinationProposal extends Model {
 public $incrementing=false;protected $keyType='string';protected $guarded=['id'];protected $hidden=['payload','source','receipt','review_note'];
 protected function casts():array {return ['payload'=>'encrypted:array','source'=>'encrypted:array','receipt'=>'encrypted:array','review_note'=>'encrypted','checked_at'=>'datetime','withdrawn_at'=>'datetime','version'=>'integer'];}
}
