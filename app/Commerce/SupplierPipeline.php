<?php

namespace App\Commerce;

use App\Models\SupplierSyncRun;
use App\Support\AdminAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SupplierPipeline
{
    public function __construct(private SupplierDownloader $download, private SupplierSync $sync, private SupplierNotifications $notifications) {}

    public function fetch(int $actorId, bool $apply = false, bool $scheduled = false): SupplierSyncRun
    {
        $dailyKey = $scheduled ? now('America/Mexico_City')->format('Y-m-d') : null;
        $run = DB::transaction(function () use ($actorId, $dailyKey): SupplierSyncRun {
            $lock = DB::table('commerce_locks')->where('id', 1)->lockForUpdate()->first();
            if ($dailyKey && ($existing = SupplierSyncRun::where('daily_key', $dailyKey)->first())) {
                if (! $existing->finished_at && $lock->sync_uuid === $existing->uuid && now()->gte($lock->sync_expires_at)) {
                    $existing->update(['status' => 'failed', 'phase' => 'processing', 'error_code' => 'interrupted', 'finished_at' => now(), 'errors' => [['row' => 0, 'message' => 'Ejecución interrumpida; requiere revisión manual.']]]);
                    DB::table('commerce_locks')->where('id', 1)->update(['sync_uuid' => null, 'sync_expires_at' => null]);
                }

                return $existing;
            }
            abort_if($lock->sync_uuid && now()->lt($lock->sync_expires_at), 409, 'Ya hay una descarga en curso.');
            if ($lock->sync_uuid) {
                $abandoned = SupplierSyncRun::where('uuid', $lock->sync_uuid)->first();
                if ($abandoned && ! $abandoned->applied_at && ! $abandoned->finished_at) {
                    $abandoned->update(['status' => 'failed', 'phase' => 'processing', 'error_code' => 'interrupted', 'finished_at' => now(), 'errors' => [['row' => 0, 'message' => 'Ejecución interrumpida.']]]);
                    DB::afterCommit(fn () => $this->notifications->record($abandoned));
                }
            }
            $run = SupplierSyncRun::create(['uuid' => (string) Str::uuid(), 'created_by' => $actorId, 'source' => 'ftp', 'filename' => 'provider.csv', 'status' => 'running', 'phase' => 'connection', 'daily_key' => $dailyKey, 'started_at' => now(), 'base_revision' => $lock->catalog_revision, 'row_count' => 0, 'summary' => [], 'errors' => []]);
            DB::table('commerce_locks')->where('id', 1)->update(['sync_uuid' => $run->uuid, 'sync_expires_at' => now()->addMinutes(10)]);

            return $run;
        });
        if (! $run->wasRecentlyCreated) {
            if ($run->finished_at) {
                $this->notifications->record($run);
            }

            return $run;
        }
        $started = microtime(true);
        try {
            $attempts = max(1, min(3, (int) config('commerce.sync.download_attempts')));
            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                $run->update(['download_attempts' => $attempt, 'phase' => 'download']);
                try {
                    $bytes = $this->download->download();
                    break;
                } catch (SupplierTransferException $error) {
                    if (! $error->retryable || $attempt === $attempts) {
                        throw $error;
                    }
                    if (! app()->runningUnitTests()) {
                        sleep($attempt * 2);
                    }
                }
            }
            $lease = DB::table('commerce_locks')->where('id', 1)->first();
            abort_unless($lease->sync_uuid === $run->uuid && now()->lt($lease->sync_expires_at), 409, 'La ejecución perdió su bloqueo.');
            $run->update(['phase' => 'processing']);
            $run = $this->sync->preview($bytes, basename((string) config('commerce.sync.ftp_path', 'provider.csv')), $actorId, 'ftp', $run);
            if ($apply && $run->status === 'ready') {
                if (SupplierSyncRun::where('status', 'review')->whereKeyNot($run->id)->exists()) {
                    $run->update(['status' => 'review', 'error_code' => 'prior_review_pending']);
                } else {
                    $run->update(['phase' => 'apply']);
                    $run = $this->sync->apply($run, $actorId, (bool) config('commerce.sync.deactivate_missing'), automated: true);
                }
            }
        } catch (\Throwable $error) {
            $run->refresh();
            if (! $run->applied_at) {
                $run->update(['status' => 'failed', 'phase' => $error instanceof SupplierTransferException ? $error->phase : $run->phase, 'error_code' => $error instanceof SupplierTransferException ? $error->reason : 'operation_failed', 'errors' => [['row' => 0, 'message' => 'No se completó esta fase; revisa configuración y archivo.']]]);
            }
        } finally {
            $run->update(['finished_at' => now(), 'duration_ms' => (int) ((microtime(true) - $started) * 1000)]);
            DB::table('commerce_locks')->where('id', 1)->where('sync_uuid', $run->uuid)->update(['sync_uuid' => null, 'sync_expires_at' => null]);
            try {
                app(AdminAuditLogger::class)->record($run->status === 'failed' ? 'supplier_sync.failed' : 'supplier_sync.finished', 'supplier_sync', $run->uuid, after: ['status' => $run->status, 'phase' => $run->phase], actorId: $actorId);
            } finally {
                $this->notifications->record($run);
            }
        }

        return $run;
    }
}
