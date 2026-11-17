<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierSyncNotification extends Model
{
    protected $guarded = ['id'];

    public function outbox(): BelongsTo
    {
        return $this->belongsTo(OutboxMessage::class, 'outbox_message_id');
    }

    public function getDeliveryStatusAttribute(): string
    {
        return $this->outbox?->status ?? $this->status;
    }
}
