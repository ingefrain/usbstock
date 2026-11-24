<?php

namespace App\Commerce;

use App\Commerce\Api\PublicQuoteIdentifiers;
use App\Models\CommercialSeller;
use App\Models\CommercialSetting;
use App\Models\ProductVariant;
use App\Models\Quote;
use App\Models\QuoteVersion;
use App\Support\AdminAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class QuoteService
{
    public function __construct(private QuoteCalculator $calculator, private QuotePdf $pdf, private AdminAuditLogger $audit) {}

    public function save(array $input, int $actorId, ?Quote $quote = null): Quote
    {
        if (array_key_exists('tax_percent', $input)) {
            Validator::make($input, ['tax_percent' => ['required', 'regex:/^\d{1,3}(\.\d{1,2})?$/']])->validate();
            $input['tax_basis_points'] = Money::parse($input['tax_percent']);
        }
        $data = Validator::make($input, [
            'revision' => 'required|integer|min:1', 'client_id' => 'nullable|integer|exists:commercial_clients,id',
            'customer_name' => 'required|string|max:180', 'customer_company' => 'nullable|string|max:180', 'customer_email' => 'required|email|max:180',
            'customer_phone' => 'nullable|string|max:50', 'customer_address' => 'nullable|string|max:2000', 'customer_country' => 'nullable|string|max:100', 'seller' => 'nullable|string|max:180',
            'seller_id' => ['nullable', 'integer', Rule::exists('commercial_sellers', 'id')->where('is_active', true)],
            'terms' => 'nullable|string|max:6000', 'validity_days' => 'required|integer|min:1|max:365', 'tax_basis_points' => 'required|integer|min:0|max:10000',
            'shipping' => ['required', 'regex:/^\d{1,7}(\.\d{1,2})?$/'], 'items' => 'required|array|min:1|max:100',
            'items.*.variant_id' => 'required|integer|distinct|exists:product_variants,id', 'items.*.quantity' => 'required|integer|min:50|max:1000000',
            'items.*.unit_price' => ['required', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
            'items.*.printing_price' => ['required', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
            'items.*.discount' => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/'], 'items.*.printing' => 'nullable|string|max:500',
            'items.*.pricing_action' => 'sometimes|in:keep,automatic', 'items.*.pricing_fingerprint' => 'nullable|string|size:64', 'items.*.pricing_reason' => 'nullable|string|max:500',
        ])->validate();

        return DB::transaction(function () use ($data, $quote, $actorId) {
            DB::table('commerce_locks')->where('id', 1)->lockForUpdate()->first();
            if ($quote) {
                $quote = Quote::lockForUpdate()->findOrFail($quote->id);
                abort_unless($quote->status === 'draft' && $quote->revision === (int) $data['revision'], 409, 'El borrador cambió o ya fue emitido.');
            }
            $previousProducts = $quote?->items()->get()->keyBy('product_variant_id');
            $previousSeller = $quote?->seller_id;
            $lines = [];
            foreach ($data['items'] as $position => $line) {
                $variant = ProductVariant::with('product')->findOrFail($line['variant_id']);
                if (! $variant->provider_active || ! $variant->is_enabled || (int) $line['quantity'] > $variant->stock) {
                    throw ValidationException::withMessages(['items' => $variant->sku.': revisa la disponibilidad y cantidad vigente.']);
                }
                $pricing = app(CommercialPricing::class)->linePricing($variant, $line, $previousProducts?->get($variant->id), $actorId);
                $lines[] = ['position' => count($lines) + 1, 'product_variant_id' => $variant->id,
                    'product' => $previousProducts?->get($variant->id)?->product ?? ['sku' => $variant->sku, 'name' => $variant->product->name, 'description' => $variant->product->description, 'color' => $variant->color, 'capacity_gb' => $variant->product->capacity_gb],
                    'quantity' => (int) $line['quantity'], 'unit_minor' => Money::parse($line['unit_price']), 'printing_minor' => Money::parse($line['printing_price']), 'discount_minor' => Money::parse($line['discount']), 'printing' => $line['printing'] ?? null, 'pricing' => $pricing];
            }
            $totals = $this->calculator->calculate($lines, (int) $data['tax_basis_points'], Money::parse($data['shipping']));
            $quote ??= new Quote(['created_by' => $actorId]);
            $sellerId = array_key_exists('seller_id', $data) ? $data['seller_id'] : $quote->seller_id;
            $sellerName = $sellerId ? CommercialSeller::findOrFail($sellerId)->name : ($data['seller'] ?? $quote->seller ?? '');
            $quote->fill(['commercial_client_id' => array_key_exists('client_id', $data) ? $data['client_id'] : $quote->commercial_client_id, 'customer' => ['name' => $data['customer_name'], 'company' => $data['customer_company'] ?? null, 'email' => $data['customer_email'], 'phone' => $data['customer_phone'] ?? null, 'address' => $data['customer_address'] ?? null, 'country' => $data['customer_country'] ?? null],
                'seller_id' => $sellerId, 'seller' => $sellerName, 'terms' => $data['terms'] ?? null, 'validity_days' => (int) $data['validity_days'], 'tax_basis_points' => (int) $data['tax_basis_points'], 'shipping_minor' => $totals['shipping'], 'totals' => $totals, 'revision' => $quote->exists ? $quote->revision + 1 : 1])->save();
            $quote->items()->delete();
            $quote->items()->createMany($lines);
            $this->audit->record('quote.saved', 'quote', $quote->uuid, after: ['revision' => $quote->revision, 'lines' => count($lines)], actorId: $actorId);
            foreach ($lines as $line) {
                $previous = $previousProducts?->get($line['product_variant_id']);
                $after = collect($line)->only(['product_variant_id', 'quantity', 'unit_minor', 'printing_minor', 'discount_minor', 'pricing'])->all();
                $before = $previous?->only(array_keys($after));
                if ($before !== $after) {
                    $this->audit->record('quote.pricing_saved', 'quote', $quote->uuid, before: $before, after: $after, actorId: $actorId);
                }
            }
            if ($previousSeller !== $quote->seller_id) {
                $this->audit->record('quote.seller_assigned', 'quote', $quote->uuid, before: ['seller_id' => $previousSeller], after: ['seller_id' => $quote->seller_id], actorId: $actorId);
            }

            return $quote;
        });
    }

    public function issue(Quote $quote, int $revision, string $requestKey, int $actorId): QuoteVersion
    {
        abort_unless(Str::isUuid($requestKey), 422);
        $path = null;
        $assetPaths = [];
        try {
            return DB::transaction(function () use ($quote, $revision, $requestKey, $actorId, &$path, &$assetPaths) {
                $lock = DB::table('commerce_locks')->where('id', 1)->lockForUpdate()->first();
                $quote = Quote::lockForUpdate()->findOrFail($quote->id);
                if ($existing = QuoteVersion::where('request_key', $requestKey)->first()) {
                    abort_unless($existing->quote_id === $quote->id, 409);

                    return $existing;
                }
                abort_unless($quote->status === 'draft' && $quote->revision === $revision, 409, 'El borrador cambió o ya fue emitido.');
                $settings = CommercialSetting::values();
                foreach (['issuer_name', 'issuer_contact'] as $field) {
                    if (blank($settings[$field] ?? null)) {
                        throw ValidationException::withMessages(['issuer' => 'Completa los datos del emisor en Configuración comercial.']);
                    }
                }
                if (blank($quote->terms)) {
                    throw ValidationException::withMessages(['terms' => 'Define las condiciones comerciales antes de emitir.']);
                }
                $lines = $quote->items()->get()->map(fn ($i) => $i->only(['product', 'quantity', 'unit_minor', 'printing_minor', 'discount_minor', 'printing', 'pricing']))->all();
                foreach ($lines as $line) {
                    if ($line['unit_minor'] === 0) {
                        throw ValidationException::withMessages(['items' => 'Completa los precios de venta antes de emitir.']);
                    }
                }
                $totals = $this->calculator->calculate($lines, $quote->tax_basis_points, $quote->shipping_minor);
                abort_unless($totals === $quote->totals, 409, 'Los totales requieren revisión.');
                if (! $quote->folio) {
                    $sequence = $lock->quote_sequence + 1;
                    DB::table('commerce_locks')->where('id', 1)->update(['quote_sequence' => $sequence]);
                    $quote->folio = 'USB-'.now()->format('Y').'-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
                }
                $version = $quote->versions()->count() + 1;
                $uuid = (string) Str::uuid();
                app(QuotePhotos::class)->freeze($quote, $uuid, $lines, $assetPaths);
                $snapshot = ['folio' => $quote->folio, 'version' => $version, 'issued_at' => now()->toIso8601String(), 'valid_until' => now()->addDays($quote->validity_days)->toDateString(),
                    'issuer' => $settings, 'customer' => $quote->customer, 'client_id' => $quote->commercial_client_id, 'seller' => $quote->seller, 'seller_id' => $quote->seller_id, 'seller_original_name' => $quote->seller_original_name,
                    'source_request' => $quote->sourceRequest?->only(['uuid', 'created_at', 'has_seller', 'seller_name', 'message']),
                    'terms' => $quote->terms, 'tax_basis_points' => $quote->tax_basis_points, 'items' => $lines, 'totals' => $totals, 'calculation_version' => $quote->calculation_version];
                $bytes = $this->pdf->render($snapshot);
                $path = 'quotes/'.$quote->uuid.'/'.$uuid.'.pdf';
                if (! Storage::disk('local')->put($path, $bytes)) {
                    throw new \RuntimeException('No se pudo guardar el PDF privado.');
                }
                $record = $quote->versions()->create(['uuid' => $uuid, 'version' => $version, 'request_key' => $requestKey, 'snapshot' => $snapshot, 'path' => $path, 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes), 'renderer_version' => QuotePdf::VERSION, 'created_by' => $actorId, 'created_at' => now()]);
                if (config('quote_api.enabled') === true) {
                    app(PublicQuoteIdentifiers::class)->assign($quote);
                }
                $quote->status = 'issued';
                $quote->revision++;
                $quote->save();
                $this->audit->record('quote.issued', 'quote', $quote->uuid, after: ['version' => $version], actorId: $actorId);

                return $record;
            });
        } catch (\Throwable $error) {
            foreach ($assetPaths as $assetPath) {
                Storage::disk('local')->delete($assetPath);
            }
            if ($path) {
                Storage::disk('local')->delete($path);
            } throw $error;
        }
    }

    public function revise(Quote $quote, int $revision, int $actorId): void
    {
        DB::transaction(function () use ($quote, $revision, $actorId) {
            $quote = Quote::lockForUpdate()->findOrFail($quote->id);
            abort_unless($quote->status === 'issued' && $quote->revision === $revision, 409);
            $quote->update(['status' => 'draft', 'revision' => $quote->revision + 1]);
            $this->audit->record('quote.revision_started', 'quote', $quote->uuid, actorId: $actorId);
        });
    }
}
