<?php

namespace App\Commerce;

use App\Models\SupplierSyncNotification;
use App\Models\SupplierSyncRun;
use App\Services\Outbox\OutboxDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class SupplierNotifications
{
    public function record(SupplierSyncRun $run): void
    {
        try {
            $notice = SupplierSyncNotification::firstOrCreate(
                ['supplier_sync_run_id' => $run->id, 'event' => $run->status],
                ['text' => $this->text($run), 'status' => 'pending'],
            );
            $this->enqueue($notice);
        } catch (\Throwable) {
            // The durable run is also the reconciliation source if notification storage is unavailable.
            Log::warning('supplier.notification_pending', ['run' => $run->uuid]);
        }
    }

    public function enqueue(SupplierSyncNotification $notice): void
    {
        if (! config('commerce.telegram_enabled')) {
            return;
        }
        try {
            DB::transaction(function () use ($notice): void {
                $notice = SupplierSyncNotification::lockForUpdate()->findOrFail($notice->id);
                if ($notice->outbox_message_id) {
                    return;
                }
                $run = SupplierSyncRun::findOrFail($notice->supplier_sync_run_id);
                $message = app(OutboxDispatcher::class)->telegram($notice->text, category: 'supplier.sync', idempotencyKey: 'supplier:'.$run->uuid.':'.$notice->event, correlationId: $run->uuid);
                $notice->update(['outbox_message_id' => $message->id, 'status' => 'queued']);
            });
        } catch (\Throwable) {
            // Do not retain transport exceptions: they may contain bot tokens or remote addresses.
            Log::warning('supplier.notification_enqueue_failed', ['notice_id' => $notice->id]);
        }
    }

    public function text(SupplierSyncRun $run): string
    {
        $s = $run->summary ?? [];
        $changed = ($s['new'] ?? 0) + ($s['changed'] ?? 0) + ($s['models_changed'] ?? 0) + ($s['deactivated'] ?? 0);
        $status = match ($run->status) {
            'completed' => $changed ? 'Correcta con cambios' : 'Correcta sin cambios',
            'ready' => 'Vista previa; sin aplicar',
            'review' => 'Pendiente de aprobación',
            'invalid' => 'Rechazada por validaciones',
            'dismissed' => 'Revisión cerrada sin aplicar',
            default => match ($run->phase) {
                'connection' => 'Fallo de conexión FTP', 'download' => 'Fallo de descarga',
                'apply' => 'Fallo al aplicar cambios', default => 'Fallo de procesamiento',
            },
        };
        if (in_array($run->status, ['ready', 'completed'], true) && ! empty($s['warnings'])) {
            $status .= ' · con advertencias';
        }
        $environment = mb_substr(preg_replace('/[^a-zA-Z0-9 _.-]/', '', (string) config('commerce.environment_label')), 0, 40);
        $text = 'USB Stock — Sincronización diaria ['.$environment."]\nFecha: ".($run->started_at ?? $run->created_at)->copy()->timezone('America/Mexico_City')->format('d/m/Y')."\nHora programada: 00:00, Guadalajara\nEstado: ".$status;
        // Never reflect a user-controlled filename or supplier path into Telegram.
        $text .= "\nArchivo: ".($run->sha256 ? 'proveedor-'.substr($run->sha256, 0, 8).'.csv' : 'no recibido');
        $text .= "\nRecibidos: ".($s['received_count'] ?? $run->row_count).' · Únicos: '.($s['unique_count'] ?? $run->row_count)
            ."\nDuplicados consolidados: ".($s['duplicates_consolidated'] ?? 0).' · Descripciones vacías: '.($s['empty_descriptions'] ?? 0)
            ."\nAdvertencias: ".count($s['warnings'] ?? []).' · Pendientes de contenido: '.count($s['pending_content'] ?? [])
            ."\nAnomalías pendientes: ".($run->status === 'review' ? 'sí' : 'no');
        $text .= "\nVariantes procesadas: ".$run->row_count."\nNuevos: ".($s['new'] ?? 0).' · Actualizados: '.($s['changed'] ?? 0)."\nSin cambios: ".($s['unchanged'] ?? 0).' · Desactivados: '.($s['deactivated'] ?? 0)."\nErrores: ".($s['error_count'] ?? count($run->errors ?? []))."\nDuración: ".number_format(($run->duration_ms ?? 0) / 1000, 1, '.', '').' s';
        if ($run->status === 'failed') {
            $text .= "\nFase: ".$run->phase."\nMotivo: operación interrumpida o configuración/archivo no válido.\nCambios aplicados: ".($run->applied_at ? 'sí' : 'no')."\nAcción: revisar ejecución en administración antes de reintentar.";
        } elseif ($run->status === 'review') {
            $text .= "\nCambios aplicados: no. Acción: revisar diferencias y aprobar expresamente.";
        }

        return $text."\nIdentificador: ".substr($run->uuid, 0, 8);
    }
}
