<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class EvidenceRestoreRun extends Model
{
    public $incrementing=false;
    protected $keyType='string';
    protected $guarded=[];
    protected $attributes=['state'=>'Prepared','version'=>1];
    protected function casts(): array {return ['manifest'=>'array','result'=>'array','version'=>'integer','started_at'=>'datetime','finished_at'=>'datetime'];}
}
