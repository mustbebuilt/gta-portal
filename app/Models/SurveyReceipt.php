<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SurveyReceipt extends Model
{
    use HasFactory;

    protected $attributes = ['version' => 1, 'receipt_revision' => 1];

    protected $fillable = ['apprentice_id', 'recorded_by', 'kind', 'submitted_date'];

    protected function casts(): array
    {
        return ['submitted_date' => 'date', 'checks' => 'array', 'history' => 'array', 'version' => 'integer', 'receipt_revision' => 'integer'];
    }

    public function currentCheck(): ?array
    {
        $check = collect($this->checks ?? [])->last();

        return ($check['receipt_revision'] ?? null) === $this->receipt_revision ? $check : null;
    }
}
