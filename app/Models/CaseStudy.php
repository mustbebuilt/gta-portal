<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CaseStudy extends Model
{
    use HasFactory;

    protected $attributes = ['version' => 1, 'status' => 'Draft', 'privacy' => false];

    protected $fillable = ['author_id', 'fields', 'privacy'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'privacy' => 'boolean', 'history' => 'array', 'approved_copies' => 'array', 'version' => 'integer'];
    }

    public function currentApprovedCopy(): ?array
    {
        if ($this->status !== 'Approved') {
            return null;
        }

return collect($this->approved_copies ?? [])->firstWhere('version', $this->version);
    }
}
