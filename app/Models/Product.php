<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasUuids;

    public const IMAGE_CATEGORY = 'product.image';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'provider_active' => 'boolean', 'capacity_gb' => 'integer', 'synced_at' => 'datetime'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function documents()
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function image()
    {
        return $this->morphOne(Document::class, 'documentable')->ofMany(['id' => 'max'], fn ($q) => $q->where('category', self::IMAGE_CATEGORY));
    }

    public function getNameAttribute(): string
    {
        return $this->custom_name ?? $this->external_key;
    }

    public function getDescriptionAttribute(): string
    {
        return $this->custom_description ?? $this->supplier_description;
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true)->where('provider_active', true)->where(fn ($q) => $q->whereNull('product_category_id')->orWhereHas('category', fn ($c) => $c->where('is_published', true)));
    }

    public function isPublic(): bool
    {
        return $this->is_published && $this->provider_active && (! $this->category || $this->category->is_published);
    }

    public function scopeCatalogVisible($query)
    {
        return $query->published()->whereHas('variants', fn ($q) => $q->where('provider_active', true)->where('is_enabled', true));
    }
}
