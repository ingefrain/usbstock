<?php

namespace App\Commerce;

class SupplierDownloader
{
    public array $lastTransfer = [];

    // Stable libcurl error codes; PHP 8.2 on Windows does not expose these aliases.
    private const LOGIN_DENIED = 67;

    private const CERTIFICATE_FAILED = 60;

    public function download(): string
    {
        $this->lastTransfer = [];
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
        $url = ($protocol === 'ftps-implicit' ? 'ftps://' : 'ftp://').$host.':'.$port.implode('/', array_map('rawurlencode', explode('/', $path)));
        $deadline = microtime(true) + 120;
        $before = $this->request($url, $protocol, true, $deadline);
        if ($before['size'] < 0 || $before['modified'] < 0) {
            throw new SupplierTransferException('download', 'metadata_unavailable');
        }
        if ($before['size'] > config('commerce.sync.max_bytes')) {
            throw new SupplierTransferException('download', 'too_large');
        }
        $download = $this->request($url, $protocol, false, $deadline);
        $after = $this->request($url, $protocol, true, $deadline);
        if ($before['size'] !== $after['size'] || $before['modified'] !== $after['modified']
            || $download['modified'] !== $before['modified'] || strlen($download['bytes']) !== $before['size']) {
            throw new SupplierTransferException('download', 'remote_changed');
        }
        $this->lastTransfer = ['size' => $before['size'], 'modified_unix' => $before['modified'], 'metadata_stable' => true];

        return $download['bytes'];
    }

    private function request(string $url, string $protocol, bool $metadata, float $deadline): array
    {
        $remaining = (int) ceil($deadline - microtime(true));
        if ($remaining < 1) {
            throw new SupplierTransferException('download', 'timeout', true);
        }
        $curl = curl_init($url);
        if ($curl === false) {
            throw new SupplierTransferException('connection', 'initialization');
        }
        $bytes = '';
        $tooLarge = false;
        try {
            curl_setopt_array($curl, [CURLOPT_USERPWD => config('commerce.sync.ftp_user').':'.config('commerce.sync.ftp_password'), CURLOPT_CONNECTTIMEOUT => min(15, $remaining), CURLOPT_TIMEOUT => $remaining,
                CURLOPT_PROTOCOLS => CURLPROTO_FTP | CURLPROTO_FTPS, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROXY => '', CURLOPT_NOBODY => $metadata, CURLOPT_FILETIME => true,
                CURLOPT_FTP_USE_EPSV => true, CURLOPT_FTP_SKIP_PASV_IP => true,
                CURLOPT_TRANSFERTEXT => false, CURLOPT_UPLOAD => false, CURLOPT_VERBOSE => false,
                CURLOPT_USE_SSL => $protocol === 'ftp' ? CURLUSESSL_NONE : CURLUSESSL_ALL,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$bytes, &$tooLarge): int {
                    if (strlen($bytes) + strlen($chunk) > config('commerce.sync.max_bytes')) {
                        $tooLarge = true;

                        return 0;
                    }
                    $bytes .= $chunk;

                    return strlen($chunk);
                },
            ]);
            if (! curl_exec($curl)) {
                $code = curl_errno($curl);
                $connection = in_array($code, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, self::LOGIN_DENIED, CURLE_SSL_CONNECT_ERROR, self::CERTIFICATE_FAILED], true);
                $retryable = in_array($code, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_OPERATION_TIMEDOUT, CURLE_PARTIAL_FILE, CURLE_RECV_ERROR], true);
                $reason = match ($code) {
                    self::LOGIN_DENIED => 'authentication_failed', CURLE_OPERATION_TIMEDOUT => 'timeout',
                    CURLE_PARTIAL_FILE => 'partial_file', self::CERTIFICATE_FAILED => 'certificate_failed',
                    default => $tooLarge ? 'too_large' : ($connection ? 'connection_failed' : 'download_failed'),
                };
                throw new SupplierTransferException($connection ? 'connection' : 'download', $reason, $retryable);
            }

            return ['bytes' => $bytes, 'size' => (int) curl_getinfo($curl, CURLINFO_CONTENT_LENGTH_DOWNLOAD_T), 'modified' => (int) curl_getinfo($curl, CURLINFO_FILETIME_T)];
        } finally {
            curl_close($curl);
        }
    }
}
