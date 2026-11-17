<?php

namespace App\Commerce;

class SupplierDownloader
{
    public function download(): string
    {
        if (! config('commerce.sync.ftp_enabled')) {
            throw new SupplierTransferException('connection', 'disabled');
        }
        $host = config('commerce.sync.ftp_host');
        $path = config('commerce.sync.ftp_path');
        $protocol = config('commerce.sync.ftp_protocol');
        $port = (int) config('commerce.sync.ftp_port');
        if (! in_array($protocol, ['ftp', 'ftps-explicit', 'ftps-implicit'], true) || $port < 1 || $port > 65535
            || ! is_string($host) || ! preg_match('/\A[a-z0-9.-]+\z/i', $host) || ! is_string($path) || ! str_starts_with($path, '/') || str_contains($path, '..') || preg_match('/[\x00-\x1f\x7f]/', $path)
            || ! config('commerce.sync.ftp_user') || ! config('commerce.sync.ftp_password')) {
            throw new SupplierTransferException('connection', 'configuration');
        }
        $curl = curl_init(($protocol === 'ftps-implicit' ? 'ftps://' : 'ftp://').$host.':'.$port.implode('/', array_map('rawurlencode', explode('/', $path))));
        $bytes = '';
        try {
            curl_setopt_array($curl, [CURLOPT_USERPWD => config('commerce.sync.ftp_user').':'.config('commerce.sync.ftp_password'), CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 120,
                CURLOPT_PROTOCOLS => CURLPROTO_FTP | CURLPROTO_FTPS, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_USE_SSL => $protocol === 'ftp' ? CURLUSESSL_NONE : CURLUSESSL_ALL,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$bytes): int {
                    if (strlen($bytes) + strlen($chunk) > config('commerce.sync.max_bytes')) {
                        return 0;
                    }
                    $bytes .= $chunk;

                    return strlen($chunk);
                },
            ]);
            if (! curl_exec($curl)) {
                $code = curl_errno($curl);
                $connection = in_array($code, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_LOGIN_DENIED, CURLE_SSL_CONNECT_ERROR, CURLE_PEER_FAILED_VERIFICATION], true);
                $retryable = in_array($code, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_OPERATION_TIMEDOUT, CURLE_PARTIAL_FILE, CURLE_RECV_ERROR], true);
                throw new SupplierTransferException($connection ? 'connection' : 'download', $connection ? 'connection_failed' : 'download_failed', $retryable);
            }

            return $bytes;
        } finally {
            curl_close($curl);
        }
    }
}
