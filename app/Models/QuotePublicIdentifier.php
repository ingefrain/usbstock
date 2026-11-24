<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuotePublicIdentifier extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'public_id';

    protected $fillable = ['public_id', 'quote_id', 'origin', 'source_id', 'created_at'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Los identificadores públicos son inmutables.'));
        static::deleting(fn () => throw new \LogicException('Los identificadores públicos no se eliminan ni reutilizan.'));
    }

    protected function casts(): array
    {
        return ['public_id' => 'integer', 'quote_id' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    public function quote()
    {
        return $this->belongsTo(Quote::class);
    }
}
