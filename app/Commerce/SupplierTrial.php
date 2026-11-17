<?php

namespace App\Commerce;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class SupplierTrial
{
    public function preflight(?User $actor, string $baseline): array
    {
        $issues = [];
        $settings = config('commerce.sync');
        if (! CommerceAccess::allows($actor, 'supplier_sync.create')) {
            $issues[] = 'actor_not_authorized';
        }
        foreach (['commerce.sync.ftp_enabled', 'commerce.sync.enabled', 'commerce.sync.auto_apply', 'commerce.telegram_enabled', 'services.telegram.enabled', 'notifications.transports.telegram.enabled', 'commerce.notify_email'] as $flag) {
            if (config($flag)) {
                $issues[] = 'must_be_disabled:'.$flag;
            }
        }
        foreach (['ftp_host', 'ftp_user', 'ftp_password'] as $key) {
            if (! is_string($settings[$key] ?? null) || $settings[$key] === '') {
                $issues[] = 'missing:SUPPLIER_'.strtoupper($key);
            }
        }
        $protocol = $settings['ftp_protocol'] ?? '';
        if (! in_array($protocol, ['ftp', 'ftps-explicit', 'ftps-implicit'], true)) {
            $issues[] = 'explicit_protocol_required';
        }
        if (($settings['ftp_path'] ?? '') !== '/FTP/Existencias-ftp-precio.csv') {
            $issues[] = 'unexpected_remote_path';
        }
        if (! is_string($settings['ftp_host'] ?? null) || ! preg_match('/\A[a-z0-9.-]+\z/i', $settings['ftp_host'])) {
            $issues[] = 'invalid_host';
        }
        $port = filter_var($settings['ftp_port'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if (! $port) {
            $issues[] = 'invalid_port';
        }
        if ($protocol === 'ftp' && ! ($settings['trial_allow_plaintext'] ?? false)) {
            $issues[] = 'plaintext_requires_explicit_acceptance';
        }
        $connection = config('database.connections.'.config('database.default'));
        $expectedDatabase = $settings['trial_database'] ?? '';
        if (! app()->environment('testing') && (! preg_match('/\Ausbstock_(dev|trial|staging)\z/', $expectedDatabase)
            || ($connection['database'] ?? '') !== $expectedDatabase || ! empty($connection['url']))) {
            $issues[] = 'dedicated_trial_database_required';
        }
        if (! extension_loaded('curl') || ! in_array($protocol === 'ftps-implicit' || $protocol === 'ftps-explicit' ? 'ftps' : 'ftp', curl_version()['protocols'] ?? [], true)) {
            $issues[] = 'curl_protocol_unavailable';
        }
        if (PHP_OS_FAMILY !== 'Windows' && ! app()->environment('testing')) {
            foreach ([app()->environmentFilePath(), app()->getCachedConfigPath()] as $configPath) {
                if (is_file($configPath) && (fileperms($configPath) & 0077) !== 0) {
                    $issues[] = 'private_config_permissions_required';
                    break;
                }
            }
        }
        $baselineSummary = null;
        if (! is_file($baseline) || ! is_readable($baseline) || filesize($baseline) > config('commerce.sync.max_bytes')) {
            $issues[] = 'invalid_baseline_file';
        } else {
            $bytes = file_get_contents($baseline);
            $baselineSummary = app(SupplierCsvComparison::class)->compare($bytes, $bytes)['baseline'];
            if (! $baselineSummary['valid']) {
                $issues[] = 'invalid_baseline_csv';
            }
        }

        return ['ready' => $issues === [], 'network_used' => false, 'actor_id' => $actor?->id,
            'database' => $connection['database'] ?? null, 'protocol' => in_array($protocol, ['ftp', 'ftps-explicit', 'ftps-implicit'], true) ? $protocol : 'unset_or_invalid', 'port' => $port,
            'passive' => 'EPSV with PASV fallback; control connection IP',
            'baseline' => $baselineSummary, 'issues' => $issues];
    }

    public function download(User $actor, string $baseline): array
    {
        $check = $this->preflight($actor, $baseline);
        if (! $check['ready']) {
            throw new RuntimeException('Preflight must pass before connecting.');
        }
        $settings = ['commerce.sync.ftp_enabled' => true, 'commerce.sync.download_attempts' => 1];
        $previous = collect($settings)->mapWithKeys(fn ($value, $key) => [$key => config($key)])->all();
        config($settings);
        try {
            return app(SupplierReadOnly::class)->run(function () use ($actor, $baseline): array {
                $fingerprint = app(SupplierCatalogFingerprint::class);
                $before = $fingerprint->capture();
                $run = app(SupplierPipeline::class)->fetch($actor->id, false, false);
                $after = $fingerprint->capture();
                $comparison = $run->path ? app(SupplierCsvComparison::class)->compare(file_get_contents($baseline), Storage::disk('local')->get($run->path)) : null;
                $report = ['run_uuid' => $run->uuid, 'status' => $run->status, 'phase' => $run->phase, 'error_code' => $run->error_code,
                    'started_at' => $run->started_at?->toIso8601String(), 'finished_at' => $run->finished_at?->toIso8601String(),
                    'duration_ms' => $run->duration_ms, 'sha256' => $run->sha256, 'csv_path' => $run->path,
                    'transfer' => $run->summary['transfer'] ?? null,
                    'applied' => $run->applied_at !== null, 'catalog_unchanged' => $before === $after,
                    'before' => $before, 'after' => $after, 'comparison' => $comparison];
                $path = 'supplier-trials/'.$run->uuid.'.json';
                if (! Storage::disk('local')->put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
                    throw new RuntimeException('Could not retain private trial report.');
                }
                $run->update(['summary' => array_merge($run->summary ?? [], ['trial' => ['catalog_unchanged' => $report['catalog_unchanged'],
                    'report_path' => $path, 'comparison_suspicious' => $comparison['changes']['suspicious'] ?? null]])]);
                unset($report['comparison']['changes']['skus']);

                return $report + ['report_path' => $path];
            });
        } finally {
            // Neither the application .env nor its cache is changed, including on failure.
            config($previous);
        }
    }
}
