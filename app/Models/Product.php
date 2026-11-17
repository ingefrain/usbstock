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
        return trim($this->custom_description ?? '') !== '' ? $this->custom_description : ($this->supplier_description ?? '');
    }

    public function getContentReviewRequiredAttribute(): bool
    {
        return trim($this->description) === '';
    }

    public function scopeWithCommercialDescription($query)
    {
        return $query->where(function ($q): void {
            foreach (['supplier_description', 'custom_description'] as $column) {
                $expression = "COALESCE($column, '')";
                foreach ([9, 10, 11, 12, 13] as $code) {
                    $expression = "REPLACE($expression, CHAR($code), '')";
                }
                $q->orWhereRaw("TRIM($expression) <> ''");
            }
        });
    }

    public function scopePublished($query)
    {
        return $query->withCommercialDescription()->where('is_published', true)->where('provider_active', true)->where(fn ($q) => $q->whereNull('product_category_id')->orWhereHas('category', fn ($c) => $c->where('is_published', true)));
    }

    public function isPublic(): bool
    {
        return ! $this->content_review_required && $this->is_published && $this->provider_active && (! $this->category || $this->category->is_published);
    }

    public function scopeCatalogVisible($query)
    {
        return $query->published()->whereHas('variants', fn ($q) => $q->where('provider_active', true)->where('is_enabled', true));
    }
}
