<?php

namespace App\Commerce;

use Closure;

final class SupplierReadOnly
{
    public function run(Closure $operation): mixed
    {
        $forced = ['commerce.sync.read_only' => true, 'commerce.sync.enabled' => false,
            'commerce.sync.auto_apply' => false, 'commerce.sync.deactivate_missing' => false,
            'commerce.telegram_enabled' => false, 'services.telegram.enabled' => false,
            'notifications.transports.telegram.enabled' => false, 'commerce.notify_email' => null];
        $previous = collect($forced)->mapWithKeys(fn ($value, $key) => [$key => config($key)])->all();
        config($forced);
        try {
            return $operation();
        } finally {
            config($previous);
        }
    }
}
