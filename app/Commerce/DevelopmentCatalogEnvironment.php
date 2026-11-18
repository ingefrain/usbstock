<?php

namespace App\Commerce;

use RuntimeException;

/** Fail closed before any connection or mutation in the local catalog tooling. */
final class DevelopmentCatalogEnvironment
{
    public function inspect(): array
    {
        $connection = config('database.connections.'.config('database.default'), []);
        $flags = [
            'ftp' => (bool) config('commerce.sync.ftp_enabled'),
            'scheduler' => (bool) config('commerce.sync.enabled'),
            'auto_apply' => (bool) config('commerce.sync.auto_apply'),
            'commercial_telegram' => (bool) config('commerce.telegram_enabled'),
            'telegram' => (bool) config('services.telegram.enabled'),
            'commercial_mail_recipient' => filled(config('commerce.notify_email')),
        ];
        $destination = app()->environment('local')
            && in_array($connection['driver'] ?? '', ['mysql', 'mariadb'], true)
            && ($connection['host'] ?? '') === '127.0.0.1'
            && (string) ($connection['port'] ?? '') === '3307'
            && ($connection['database'] ?? '') === 'usbstock_dev'
            && empty($connection['url']) && empty($connection['unix_socket'])
            && empty($connection['prefix'])
            && empty($connection['read']) && empty($connection['write']);

        return [
            'environment' => app()->environment(), 'expected_destination' => $destination,
            'configuration_cached' => app()->configurationIsCached(), 'flags' => $flags,
            'safe' => $destination && ! app()->configurationIsCached() && ! array_filter($flags),
        ];
    }

    public function assertSafe(): array
    {
        $report = $this->inspect();
        if (! $report['safe']) {
            throw new RuntimeException('Operación bloqueada: requiere local, usbstock_dev en 127.0.0.1:3307 y todas las integraciones desactivadas, sin configuración cacheada.');
        }

        return $report;
    }
}
