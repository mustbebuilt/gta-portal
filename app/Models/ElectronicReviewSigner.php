<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ElectronicReviewSigner extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'electronic_review_signers';

    protected $guarded = [];

    protected $attributes = ['state' => 'Pending', 'version' => 1];

    protected $hidden = ['attestation', 'attestation_hash', 'identity_hash'];

    protected function casts(): array
    {
        return ['attestation' => 'encrypted:array', 'attested_at' => 'datetime', 'auth_version' => 'integer', 'version' => 'integer'];
    }
}
