<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuoteItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['product' => 'array', 'quantity' => 'integer', 'unit_minor' => 'integer', 'printing_minor' => 'integer', 'discount_minor' => 'integer'];
    }
}
