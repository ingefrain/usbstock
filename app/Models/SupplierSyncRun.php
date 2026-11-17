<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierSyncRun extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['summary' => 'array', 'errors' => 'array', 'differences' => 'array', 'applied_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(SupplierSyncNotification::class);
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'ready' => empty($this->summary['warnings']) ? 'Archivo válido sin advertencias; sin aplicar' : 'Archivo válido con advertencias; sin aplicar',
            'review' => 'Pendiente de aprobación por anomalías',
            'invalid' => 'Archivo inválido; aplicación bloqueada',
            'completed' => empty($this->summary['warnings']) ? 'Aplicada' : 'Aplicada con advertencias',
            'failed' => $this->phase === 'apply' ? 'Aplicación fallida' : 'Procesamiento fallido',
            'dismissed' => 'Cerrada sin aplicar', default => 'En proceso',
        };
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
