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

    public function preview(string $bytes, string $filename, ?int $actorId, string $source = 'local'): SupplierSyncRun
    {
        $started = microtime(true);
        abort_if(strlen($bytes) > config('commerce.sync.max_bytes'), 422, 'Archivo demasiado grande.');
        $uuid = (string) Str::uuid();
        $path = 'supplier-sync/'.$uuid.'.csv';
        if (! Storage::disk('local')->put($path, $bytes)) {
            throw ValidationException::withMessages(['file' => 'No se pudo guardar el archivo privado.']);
        }
        try {
            return DB::transaction(function () use ($bytes, $filename, $actorId, $source, $started, $uuid, $path): SupplierSyncRun {
                $lock = DB::table('commerce_locks')->where('id', 1)->lockForUpdate()->first();
                try {
                    $data = $this->csv->parse($bytes);
                } catch (ValidationException $error) {
                    $data = ['rows' => [], 'errors' => [['row' => 0, 'message' => $error->validator->errors()->first()]], 'sha256' => hash('sha256', $bytes)];
                }
                $summary = $this->compare($data['rows']);
                $run = SupplierSyncRun::create([
                    'uuid' => $uuid, 'created_by' => $actorId, 'source' => $source,
                    'filename' => Str::limit(basename(str_replace('\\', '/', $filename)), 170, ''),
                    'path' => $path, 'sha256' => $data['sha256'], 'base_revision' => $lock->catalog_revision,
                    'row_count' => count($data['rows']), 'summary' => $summary, 'errors' => $data['errors'],
                    'status' => $data['errors'] ? 'invalid' : ($summary['suspicious'] ? 'review' : 'ready'),
                    'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                ]);
                $this->audit->record('supplier_sync.previewed', 'supplier_sync', $uuid, after: ['status' => $run->status, 'rows' => $run->row_count], actorId: $actorId);
                app(CommerceNotifier::class)->queue('sync.'.$run->status, $run->uuid, 'Validación del proveedor: '.$run->status.'. Registros: '.$run->row_count.'.');

                return $run;
            });
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
    }

    public function rows(SupplierSyncRun $run): array
    {
        abort_unless($run->path === 'supplier-sync/'.$run->uuid.'.csv', 409);
        $bytes = Storage::disk('local')->get($run->path);
        abort_unless(is_string($bytes) && hash_equals($run->sha256, hash('sha256', $bytes)), 409, 'El archivo cambió o ya no está disponible.');

        return $this->csv->parse($bytes);
    }

    public function apply(SupplierSyncRun $run, ?int $actorId, bool $deactivateMissing = false, bool $approveLargeChanges = false): SupplierSyncRun
    {
        $started = microtime(true);

        return DB::transaction(function () use ($run, $actorId, $deactivateMissing, $approveLargeChanges, $started): SupplierSyncRun {
            $lock = DB::table('commerce_locks')->where('id', 1)->lockForUpdate()->first();
            $run = SupplierSyncRun::query()->lockForUpdate()->findOrFail($run->id);
            abort_unless(in_array($run->status, ['ready', 'review'], true), 409, 'La corrida no está disponible para aplicar.');
            abort_unless((int) $run->base_revision === (int) $lock->catalog_revision, 409, 'El catálogo cambió. Genera otra vista previa.');
            abort_if($run->created_at->lt(now()->subDays(7)), 409, 'La vista previa expiró.');
            $data = $this->rows($run);
            abort_if($data['errors'] !== [] || $data['rows'] === [], 409, 'Archivo inválido.');
            $summary = $this->compare($data['rows']);
            abort_if($summary['suspicious'] && ! $approveLargeChanges, 409, 'Los cambios importantes requieren revisión adicional.');
            $models = [];
            foreach ($data['rows'] as $row) {
                if (! isset($models[$row['model']])) {
                    $product = Product::firstOrNew(['external_key' => $row['model']]);
                    if (! $product->exists) {
                        $product->slug = strtolower($row['model']);
                    }
                    $product->fill(['supplier_description' => $row['supplier_description'], 'provider_active' => true, 'synced_at' => now()])->save();
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
            DB::table('commerce_locks')->where('id', 1)->increment('catalog_revision');
            $run->update(['status' => 'completed', 'summary' => $summary, 'applied_at' => now(), 'duration_ms' => $run->duration_ms + (int) ((microtime(true) - $started) * 1000)]);
            $this->audit->record('supplier_sync.applied', 'supplier_sync', $run->uuid, after: $summary, actorId: $actorId);
            app(CommerceNotifier::class)->queue('sync.completed', $run->uuid, 'La sincronización del catálogo se aplicó correctamente.');

            return $run;
        });
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
