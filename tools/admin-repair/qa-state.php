<?php

use App\Commerce\QuoteService;
use App\Models\AdminAuditEvent;
use App\Models\CommercialPriceScale;
use App\Models\ProductVariant;
use App\Models\Quote;
use App\Models\QuoteVersion;
use Illuminate\Support\Facades\Storage;

if (PHP_SAPI !== 'cli') {
    exit;
}
require __DIR__.'/qa-bootstrap.php';
$mode = $argv[1] ?? 'verify';
if ($mode === 'restart-unissued-fixture') {
    $prepared = json_decode(file_get_contents($reviewRoot.'/prepared.json'), true, flags: JSON_THROW_ON_ERROR);
    $quote = Quote::where('uuid', $prepared['quote_uuid'])->sole();
    if ($quote->status !== 'draft' || $quote->versions()->exists() || QuoteVersion::exists()) {
        throw new RuntimeException('Only the interrupted unissued fixture can be restarted.');
    }
    ProductVariant::where('sku', 'VAR015-NE')->sole()->update(['supplier_price_minor' => 400]);
    CommercialPriceScale::where('source_key', 'usbstock2.productos_escalas:1')->sole()->update(['is_active' => true]);
    $items = $quote->items->map(fn ($i) => ['variant_id' => $i->product_variant_id, 'quantity' => 50, 'unit_price' => $i->variant->sku === 'USB082-NT' ? '99' : '0', 'printing_price' => '0', 'discount' => '0', 'pricing_reason' => 'Reinicio de fixture aislada después del corte eléctrico'])->all();
    app(QuoteService::class)->save(['revision' => $quote->revision, 'customer_name' => 'Cliente QA aislado', 'customer_email' => 'client@example.test', 'terms' => 'Documento ficticio de prueba. No enviar.', 'validity_days' => 30, 'tax_basis_points' => 1600, 'shipping' => '0', 'items' => $items], $quote->created_by, $quote);
    $quote->items()->update(['pricing' => null]);
} elseif ($mode === 'change-cost' || $mode === 'restore-cost') {
    ProductVariant::where('sku', 'VAR015-NE')->sole()->update(['supplier_price_minor' => $mode === 'change-cost' ? 800 : 400]);
} elseif ($mode === 'disable-band' || $mode === 'restore-band') {
    CommercialPriceScale::where('source_key', 'usbstock2.productos_escalas:1')->sole()->update(['is_active' => $mode === 'restore-band']);
} elseif ($mode === 'pdf-baseline' || $mode === 'verify') {
    $pdfs = QuoteVersion::all()->mapWithKeys(fn ($v) => [$v->uuid => ['sha256' => hash_file('sha256', Storage::disk('local')->path($v->path)), 'snapshot_hash' => hash('sha256', json_encode($v->snapshot, JSON_THROW_ON_ERROR))]])->all();
    if ($mode === 'pdf-baseline') {
        if (count($pdfs) !== 1 || is_file($reviewRoot.'/pdf-baseline.json')) {
            throw new RuntimeException('One new QA PDF and no previous baseline required.');
        }
        file_put_contents($reviewRoot.'/pdf-baseline.json', json_encode($pdfs, JSON_THROW_ON_ERROR));
    } else {
        if ($pdfs !== json_decode(file_get_contents($reviewRoot.'/pdf-baseline.json'), true, flags: JSON_THROW_ON_ERROR)) {
            throw new RuntimeException('Isolated PDF or snapshot changed.');
        }
        $report = ['database' => 'isolated_sqlite', 'pdfs_unchanged' => count($pdfs), 'price_audit_events' => AdminAuditEvent::where('action', 'quote.pricing_saved')->count(), 'quotes' => Quote::with('items')->get()->map(fn ($q) => ['uuid' => $q->uuid, 'status' => $q->status, 'revision' => $q->revision, 'prices' => $q->items->pluck('unit_minor'), 'modes' => $q->items->map(fn ($i) => $i->pricing['mode'] ?? null)])->all()];
        file_put_contents($reviewRoot.'/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
} else {
    throw new RuntimeException('Unsupported isolated QA action.');
}
echo "Isolated QA action completed.\n";
