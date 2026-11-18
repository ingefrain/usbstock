<?php

namespace App\Models;

use App\Commerce\CommercialAvailability;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['provider_active' => 'boolean', 'is_enabled' => 'boolean', 'stock' => 'integer', 'supplier_price_minor' => 'integer'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeCommerciallyAvailable($query)
    {
        return $query->where('provider_active', true)->where('is_enabled', true)->where('stock', '>=', CommercialAvailability::MINIMUM);
    }

    public function getColorAttribute(): string
    {
        return $this->color_name ?? $this->color_code;
    }
}
