<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TimetableDeliveryRecord extends Model
{
    protected $hidden=['details'];
    protected $primaryKey = 'training_session_id';
    public $incrementing = false;
    protected $guarded = [];
    protected function casts(): array
    {
        return ['training_session_id'=>'integer', 'details'=>'encrypted:array', 'version'=>'integer', 'staff_id'=>'integer'];
    }
}
