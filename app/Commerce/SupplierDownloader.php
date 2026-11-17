<?php

namespace App\Commerce;

use RuntimeException;

class SupplierDownloader
{
    public function download(): string
    {
        if (! config('commerce.sync.ftp_enabled')) {
            throw new RuntimeException('La conexión al proveedor está deshabilitada.');
        }
        $host = config('commerce.sync.ftp_host');
        $path = config('commerce.sync.ftp_path');
        if (! is_string($host) || ! preg_match('/\A[a-z0-9.-]+\z/i', $host) || ! is_string($path) || ! str_starts_with($path, '/') || str_contains($path, '..')
            || ! config('commerce.sync.ftp_user') || ! config('commerce.sync.ftp_password')) {
            throw new RuntimeException('Configuración de proveedor incompleta.');
        }
        $curl = curl_init('ftp://'.$host.':'.config('commerce.sync.ftp_port').implode('/', array_map('rawurlencode', explode('/', $path))));
        $bytes = '';
        try {
            curl_setopt_array($curl, [CURLOPT_USERPWD => config('commerce.sync.ftp_user').':'.config('commerce.sync.ftp_password'), CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 120,
                CURLOPT_PROTOCOLS => CURLPROTO_FTP | CURLPROTO_FTPS, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_USE_SSL => config('commerce.sync.ftp_tls') ? CURLUSESSL_ALL : CURLUSESSL_NONE,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$bytes): int {
                    if (strlen($bytes) + strlen($chunk) > config('commerce.sync.max_bytes')) {
                        return 0;
                    }
                    $bytes .= $chunk;

                    return strlen($chunk);
                },
            ]);
            if (! curl_exec($curl) || $bytes === '') {
                throw new RuntimeException('No se pudo descargar un archivo válido del proveedor.');
            }

            return $bytes;
        } finally {
            curl_close($curl);
        }
    }
}
