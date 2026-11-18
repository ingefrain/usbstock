<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductCategory extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'sort_order' => 'integer', 'source_metadata' => 'array'];
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function assignedProducts()
    {
        return $this->belongsToMany(Product::class, 'product_category_assignments');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    // The verified historical taxonomy has two levels. Editors may attach children only to roots.
    public function scopePubliclyVisible($query)
    {
        return $query->where('is_published', true)->where(fn ($q) => $q->whereNull('parent_id')
            ->orWhereHas('parent', fn ($p) => $p->where('is_published', true)->whereNull('parent_id')));
    }

    public function isPublic(): bool
    {
        return $this->is_published && (! $this->parent_id || ($this->parent?->is_published && ! $this->parent->parent_id));
    }

    public function branchIds(): array
    {
        return [$this->id, ...$this->children()->publiclyVisible()->pluck('id')->all()];
    }
}
