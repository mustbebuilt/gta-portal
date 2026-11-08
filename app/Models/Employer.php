<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employer extends Model
{
    use HasFactory;

    protected $primaryKey = 'employer_id';

    protected $fillable = [
        'employer_name',
        'company_reg_number',
        'address_line1',
        'postcode',
        'contact_name',
        'contact_email',
        'contact_phone',
    ];

    public function apprentices(): HasMany
    {
        return $this->hasMany(Apprentice::class, 'employer_id', 'employer_id');
    }
}

