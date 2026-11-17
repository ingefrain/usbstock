<?php

namespace App\Commerce;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SupplierSyncRun;
use App\Support\AdminAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SupplierSync
{
    public function __construct(private SupplierCsv $csv, private AdminAuditLogger $audit) {}

    public function preview(string $bytes, string $filename, ?int $actorId, string $source = 'local', ?SupplierSyncRun $execution = null): SupplierSyncRun
    {
        $started = microtime(true);
        abort_if(strlen($bytes) > config('commerce.sync.max_bytes'), 422, 'Archivo demasiado grande.');
        $uuid = $execution?->uuid ?? (string) Str::uuid();
        $path = 'supplier-sync/'.$uuid.'.csv';
        if (! Storage::disk('local')->put($path, $bytes)) {
            throw ValidationException::withMessages(['file' => 'No se pudo guardar el archivo privado.']);
        }
        if ($execution) {
            $execution->update(['path' => $path, 'sha256' => hash('sha256', $bytes)]);
        }
        try {
            $result = DB::transaction(function () use ($bytes, $filename, $actorId, $source, $started, $uuid, $path, $execution): SupplierSyncRun {
                $lock = DB::table('commerce_locks')->where('id', 1)->lockForUpdate()->first();
                try {
                    $data = $this->csv->parse($bytes);
                } catch (ValidationException $error) {
                    $data = ['rows' => [], 'errors' => [['row' => 0, 'message' => $error->validator->errors()->first()]], 'sha256' => hash('sha256', $bytes)];
                }
                [$rows, $summary] = $this->prepare($data);
                $run = $execution ?? new SupplierSyncRun;
                $run->fill([
                    'uuid' => $uuid, 'created_by' => $actorId, 'source' => $source,
                    'filename' => self::safeFilename($filename),
                    'path' => $path, 'sha256' => $data['sha256'], 'base_revision' => $lock->catalog_revision,
                    'row_count' => count($data['rows']), 'summary' => $summary, 'errors' => $data['errors'],
                    'status' => $data['errors'] ? 'invalid' : ($summary['suspicious'] ? 'review' : 'ready'),
                    'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                    'started_at' => $execution?->started_at ?? now(), 'finished_at' => $execution ? null : now(), 'phase' => 'validation',
                    'differences' => $this->differences($rows),
                ])->save();
                $this->audit->record('supplier_sync.previewed', 'supplier_sync', $uuid, after: ['status' => $run->status, 'rows' => $run->row_count], actorId: $actorId);

                return $run;
            });
            app(CommerceNotifier::class)->queue('sync.'.$result->status, $result->uuid, 'Validación del proveedor: '.$result->status.'. Registros: '.$result->row_count.'.');

            return $result;
        } catch (\Throwable $error) {
            if (! $execution) {
                Storage::disk('local')->delete($path);
            }
            throw $error;
        }
    }

    public static function safeFilename(string $filename): string
    {
        return Str::limit(preg_replace('/[^a-zA-Z0-9._-]/', '_', basename(str_replace('\\', '/', $filename))), 170, '') ?: 'provider.csv';
    }

    public function rows(SupplierSyncRun $run): array
    {
        abort_unless($run->path === 'supplier-sync/'.$run->uuid.'.csv', 409);
        $bytes = Storage::disk('local')->get($run->path);
        abort_unless(is_string($bytes) && hash_equals($run->sha256, hash('sha256', $bytes)), 409, 'El archivo cambió o ya no está disponible.');

        return $this->csv->parse($bytes);
    }

    public function apply(SupplierSyncRun $run, ?int $actorId, bool $deactivateMissing = false, bool $approveLargeChanges = false, bool $automated = false): SupplierSyncRun
    {
        $started = microtime(true);

        $result = DB::transaction(function () use ($run, $actorId, $deactivateMissing, $approveLargeChanges, $started, $automated): SupplierSyncRun {
            $lock = DB::table('commerce_locks')->where('id', 1)->lockForUpdate()->first();
            $run = SupplierSyncRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($run->status === 'completed') {
                return $run;
            }
            if ($automated && SupplierSyncRun::where('status', 'review')->whereKeyNot($run->id)->exists()) {
                $run->update(['status' => 'review', 'phase' => 'validation', 'error_code' => 'prior_review_pending']);

                return $run;
            }
            if ($run->source === 'ftp' && $run->phase === 'apply') {
                abort_unless($lock->sync_uuid === $run->uuid && now()->lt($lock->sync_expires_at), 409, 'La ejecución perdió su bloqueo.');
            }
            abort_unless(in_array($run->status, ['ready', 'review'], true), 409, 'La corrida no está disponible para aplicar.');
            abort_unless((int) $run->base_revision === (int) $lock->catalog_revision, 409, 'El catálogo cambió. Genera otra vista previa.');
            abort_if($run->created_at->lt(now()->subDays(7)), 409, 'La vista previa expiró.');
            $data = $this->rows($run);
            abort_if($data['errors'] !== [] || $data['rows'] === [], 409, 'Archivo inválido.');
            [$rows, $summary, $modelDescriptions] = $this->prepare($data);
            abort_if($summary['suspicious'] && ! $approveLargeChanges, 409, 'Los cambios importantes requieren revisión adicional.');
            $models = [];
            foreach (($summary['new'] + $summary['changed'] + $summary['models_changed'] > 0 ? $rows : []) as $row) {
                if (! isset($models[$row['model']])) {
                    $product = Product::firstOrNew(['external_key' => $row['model']]);
                    if (! $product->exists) {
                        $product->slug = strtolower($row['model']);
                    }
                    $product->fill(['supplier_description' => $modelDescriptions[$row['model']], 'provider_active' => true, 'synced_at' => now()])->save();
                    $models[$row['model']] = $product;
                }
                ProductVariant::updateOrCreate(['sku' => $row['sku']], [
                    'product_id' => $models[$row['model']]->id, 'color_code' => $row['color_code'],
                    'supplier_description' => $row['supplier_description'], 'stock' => $row['stock'],
                    'supplier_price_minor' => $row['supplier_price_minor'], 'provider_active' => true,
                ]);
            }
            $summary['deactivated'] = 0;
            if ($deactivateMissing) {
                $summary['deactivated'] = ProductVariant::where('provider_active', true)->whereNotIn('sku', array_keys($data['rows']))->update(['provider_active' => false]);
                Product::whereDoesntHave('variants', fn ($q) => $q->where('provider_active', true))->update(['provider_active' => false]);
            }
            if ($summary['new'] + $summary['changed'] + $summary['models_changed'] + $summary['deactivated'] > 0) {
                DB::table('commerce_locks')->where('id', 1)->increment('catalog_revision');
            }
            $run->update(['status' => 'completed', 'phase' => 'complete', 'finished_at' => now(), 'summary' => $summary, 'applied_at' => now(), 'duration_ms' => $run->duration_ms + (int) ((microtime(true) - $started) * 1000)]);
            $this->audit->record('supplier_sync.applied', 'supplier_sync', $run->uuid, after: collect($summary)->except(['warnings', 'source_rows', 'description_sources', 'model_description_sources'])->all(), actorId: $actorId);

            return $run;
        });
        if ($result->status === 'completed') {
            app(CommerceNotifier::class)->queue('sync.completed', $result->uuid, 'La sincronización del catálogo se aplicó correctamente.');
        }

        return $result;
    }

    /** Resolve retained text under the same catalog lock used by preview and apply. */
    private function prepare(array $data): array
    {
        $rows = $data['rows'];
        $known = ProductVariant::all()->keyBy('sku');
        $products = Product::all()->keyBy('external_key');
        $sources = $modelDescriptions = $modelSources = [];
        $retained = 0;
        foreach ($rows as $sku => &$row) {
            $source = 'current_csv';
            if ($row['supplier_description'] === '') {
                $variantText = $known->get($sku)?->supplier_description ?? '';
                $modelText = $products->get($row['model'])?->supplier_description ?? '';
                $source = trim($variantText) !== '' ? 'retained_variant' : (trim($modelText) !== '' ? 'retained_model' : 'missing');
                $row['supplier_description'] = match ($source) {
                    'retained_variant' => $variantText, 'retained_model' => $modelText, default => '',
                };
                $retained += $source !== 'missing' ? 1 : 0;
            }
            $sources[$sku] = ['source' => $source, 'rows' => $data['source_rows'][$sku] ?? [], 'description_sha256' => hash('sha256', $row['supplier_description'])];
            if (in_array($source, ['retained_variant', 'retained_model'], true)) {
                $sources[$sku]['retained_text'] = $row['supplier_description'];
            }
            // Prefer a current nonblank supplier description to a retained variant.
            if (! isset($modelDescriptions[$row['model']]) || ($source === 'current_csv' && $modelSources[$row['model']]['source'] !== 'current_csv')
                || (trim($modelDescriptions[$row['model']]) === '' && trim($row['supplier_description']) !== '')) {
                $modelDescriptions[$row['model']] = $row['supplier_description'];
                $modelSources[$row['model']] = $sources[$sku] + ['sku' => $sku];
            }
        }
        unset($row);
        $pending = [];
        $newModels = $changedModels = 0;
        foreach ($modelDescriptions as $key => &$description) {
            $product = $products->get($key);
            if ($modelSources[$key]['source'] !== 'current_csv' && trim($product?->supplier_description ?? '') !== '') {
                $description = $product->supplier_description;
                $modelSources[$key] = ['source' => 'retained_model', 'description_sha256' => hash('sha256', $description), 'retained_text' => $description];
            }
            $newModels += $product ? 0 : 1;
            $changedModels += $product && ($product->supplier_description !== $description || ! $product->provider_active) ? 1 : 0;
            if (trim($description) === '' && trim($product?->custom_description ?? '') === '') {
                $pending[] = (string) $key;
            }
        }
        unset($description);
        $summary = $this->compare($rows) + [
            'policy_version' => 2, 'received_count' => $data['received_count'] ?? 0,
            'unique_count' => $data['unique_count'] ?? 0, 'duplicates_consolidated' => $data['duplicates_consolidated'] ?? 0,
            'empty_descriptions' => $data['empty_descriptions'] ?? 0, 'error_count' => $data['error_count'] ?? count($data['errors']),
            'warnings' => $data['warnings'] ?? [], 'source_rows' => $data['source_rows'] ?? [],
            'description_sources' => $sources, 'model_description_sources' => $modelSources,
            'descriptions_retained' => $retained, 'pending_content' => $pending,
            'model_count' => count($modelDescriptions), 'new_models' => $newModels, 'models_changed' => $changedModels,
        ];

        return [$rows, $summary, $modelDescriptions];
    }

    private function differences(array $rows): array
    {
        $known = ProductVariant::all()->keyBy('sku');
        $differences = [];
        foreach ($rows as $sku => $row) {
            $old = $known->get($sku);
            if (! $old || ! $old->provider_active || $old->stock !== $row['stock'] || $old->supplier_price_minor !== $row['supplier_price_minor'] || $old->supplier_description !== $row['supplier_description']) {
                $differences[] = ['sku' => $sku, 'kind' => $old ? 'changed' : 'new', 'stock_before' => $old?->stock, 'stock_after' => $row['stock'], 'cost_before' => $old?->supplier_price_minor, 'cost_after' => $row['supplier_price_minor'], 'description_changed' => $old && $old->supplier_description !== $row['supplier_description']];
            }
            if (count($differences) >= 200) {
                return $differences;
            }
        }
        foreach ($known->where('provider_active', true)->except(array_keys($rows)) as $old) {
            $differences[] = ['sku' => $old->sku, 'kind' => 'missing', 'stock_before' => $old->stock, 'stock_after' => null, 'cost_before' => $old->supplier_price_minor, 'cost_after' => null, 'description_changed' => false];
            if (count($differences) >= 200) {
                break;
            }
        }

        return $differences;
    }

    private function compare(array $rows): array
    {
        $known = ProductVariant::all()->keyBy('sku');
        $summary = ['new' => 0, 'changed' => 0, 'unchanged' => 0, 'missing' => 0, 'price_alerts' => 0, 'suspicious' => false];
        foreach ($rows as $sku => $row) {
            $old = $known->get($sku);
            if (! $old) {
                $summary['new']++;

                continue;
            }
            $changed = ! $old->provider_active || $old->stock !== $row['stock'] || $old->supplier_price_minor !== $row['supplier_price_minor'] || $old->supplier_description !== $row['supplier_description'];
            $summary[$changed ? 'changed' : 'unchanged']++;
            if (abs($old->supplier_price_minor - $row['supplier_price_minor']) * 100 > max(1, $old->supplier_price_minor) * config('commerce.sync.max_price_change_percent')) {
                $summary['price_alerts']++;
            }
        }
        $active = $known->where('provider_active', true);
        $previousStock = (int) $active->sum('stock');
        $incomingStock = array_sum(array_column($rows, 'stock'));
        $summary['stock_drop'] = $previousStock > 0 && ($previousStock - $incomingStock) * 100 > $previousStock * config('commerce.sync.max_stock_drop_percent');
        $summary['missing'] = $active->keys()->diff(array_keys($rows))->count();
        $summary['suspicious'] = $summary['stock_drop'] || $summary['price_alerts'] > 0 || ($active->count() > 0 && $active->count() * config('commerce.sync.max_missing_percent') < $summary['missing'] * 100);

        return $summary;
    }
}
