<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class QuoteRequest extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['customer' => 'array', 'items' => 'array', 'has_seller' => 'boolean', 'revision' => 'integer', 'seller_id' => 'integer', 'seller_confirmed_at' => 'datetime'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function quote()
    {
        return $this->belongsTo(Quote::class);
    }

    public function seller()
    {
        return $this->belongsTo(CommercialSeller::class, 'seller_id');
    }
}
