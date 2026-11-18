<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CommercialPriceScale extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return ['lower_minor' => 'integer', 'upper_minor' => 'integer', 'increment_basis_points' => 'integer', 'revision' => 'integer', 'is_active' => 'boolean', 'source_metadata' => 'array'];
    }
}
