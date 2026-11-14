<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class ApprenticeExperienceEntry extends Model {
 protected $guarded=['id'];
 protected function casts():array {return ['happened_on'=>'date','history'=>'array','version'=>'integer'];}
 public function apprentice():BelongsTo {return $this->belongsTo(Apprentice::class);}
}
