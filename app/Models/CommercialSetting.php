<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommercialSetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    public static function values(): array
    {
        return static::find(1)?->data ?? [];
    }
}
