<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Quote extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['customer' => 'array', 'totals' => 'array', 'revision' => 'integer', 'seller_id' => 'integer', 'shipping_minor' => 'integer', 'tax_basis_points' => 'integer'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function items()
    {
        return $this->hasMany(QuoteItem::class)->orderBy('position');
    }

    public function versions()
    {
        return $this->hasMany(QuoteVersion::class)->orderByDesc('version');
    }

    public function documents()
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function sellerRecord()
    {
        return $this->belongsTo(CommercialSeller::class, 'seller_id');
    }

    public function sourceRequest()
    {
        return $this->hasOne(QuoteRequest::class);
    }

    public function getLabelAttribute(): string
    {
        return $this->folio ?? 'Borrador '.substr($this->uuid, 0, 8);
    }
}
