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

    public function categories()
    {
        return $this->belongsToMany(ProductCategory::class, 'product_category_assignments')->withPivot(['source', 'source_id', 'source_metadata']);
    }

    public function scopeInCategories($query, array $ids)
    {
        return $query->where(fn ($q) => $q->whereIn('product_category_id', $ids)->orWhereHas('categories', fn ($c) => $c->whereIn('product_categories.id', $ids)));
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
        return $query->withCommercialDescription()->where('is_published', true)->where('provider_active', true)
            ->where(fn ($q) => $q->whereHas('category', fn ($c) => $c->publiclyVisible())
                ->orWhere(fn ($p) => $p->whereNull('product_category_id')->where(fn ($c) => $c->whereDoesntHave('categories')->orWhereHas('categories', fn ($cat) => $cat->publiclyVisible()))));
    }

    public function isPublic(): bool
    {
        if ($this->content_review_required || ! $this->is_published || ! $this->provider_active) {
            return false;
        }
        if ($this->category) {
            return $this->category->isPublic();
        }

        return ! $this->categories()->exists() || $this->categories()->publiclyVisible()->exists();
    }

    public function scopeCatalogVisible($query)
    {
        return $query->published()->whereHas('variants', fn ($q) => $q->commerciallyAvailable());
    }
}
