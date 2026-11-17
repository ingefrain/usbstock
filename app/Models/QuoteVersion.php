<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class QuoteVersion extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Las versiones emitidas son inmutables.'));
        static::deleting(fn () => throw new \LogicException('Las versiones emitidas no se eliminan.'));
    }

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'created_at' => 'datetime'];
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
}
