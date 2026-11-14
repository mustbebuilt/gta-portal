<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ElectronicReviewPacket extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'electronic_review_packets';

    protected $guarded = [];

    protected $attributes = ['state' => 'Draft', 'version' => 1];

    protected $hidden = ['snapshot', 'completion'];

    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'completion' => 'encrypted:array', 'expires_on' => 'date', 'released_at' => 'datetime', 'checked_at' => 'datetime', 'withdrawn_at' => 'datetime', 'version' => 'integer'];
    }

    public function signers()
    {
        return $this->hasMany(ElectronicReviewSigner::class, 'packet_id');
    }

    public function apprentice()
    {
        return $this->belongsTo(Apprentice::class);
    }
}
