<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ManagementPack extends Model
{
    public $timestamps=false;
    protected $guarded=['id'];
    public function capturedBy() {return $this->belongsTo(User::class,'captured_by');}
    protected function casts(): array {return ['snapshot'=>'array','created_at'=>'datetime'];}
}
