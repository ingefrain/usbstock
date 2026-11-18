<?php

namespace App\Commerce;

use App\Models\CommercialClient;
use App\Models\CommercialSeller;
use App\Models\CommercialSetting;
use App\Models\ProductVariant;
use App\Models\Quote;
use App\Models\QuoteRequest;
use App\Support\AdminAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class PublicQuoteRequests
{
    public function submit(array $customer, array $cart, ?string $message, string $key, array $seller = [], array $printing = []): QuoteRequest
    {
        $seller = Validator::make($seller, ['has_seller' => 'sometimes|boolean', 'seller_name' => ['nullable', 'string', 'max:180', 'not_regex:/[\x00-\x1F\x7F]/u']])->validate();
        $hasSeller = (bool) ($seller['has_seller'] ?? false);
        $sellerName = $hasSeller ? ($seller['seller_name'] ?? null) : null;
        if ($hasSeller && (! is_string($sellerName) || trim($sellerName) === '')) {
            throw ValidationException::withMessages(['seller_name' => 'Escribe el nombre de tu vendedor o indica que no tienes uno asignado.']);
        }
        Validator::make(['printing' => $printing], ['printing' => 'array|max:50', 'printing.*' => 'nullable|string|max:500'])->validate();
        $hash = hash('sha256', json_encode([$customer, $cart, $message, $hasSeller, $sellerName, $printing], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($customer, $cart, $message, $key, $hash, $hasSeller, $sellerName, $printing) {
            DB::table('commerce_locks')->where('id', 1)->lockForUpdate()->first();
            if ($existing = QuoteRequest::where('request_key', $key)->first()) {
                abort_unless(hash_equals($existing->payload_hash, $hash), 409, 'Esta solicitud ya fue enviada con otros datos.');

                return $existing;
            }
            if (! $cart || count($cart) > 50) {
                throw ValidationException::withMessages(['cart' => 'Selecciona entre 1 y 50 variantes.']);
            }
            $items = [];
            foreach ($cart as $id => $quantity) {
                $v = ProductVariant::with('product.category')->find($id);
                if ($problem = CommercialAvailability::problem($v, $quantity)) {
                    throw ValidationException::withMessages(['cart' => $problem.' Tu selección se conserva para revisarla.']);
                }
                $items[] = ['variant_id' => $v->id, 'sku' => $v->sku, 'name' => $v->product->name, 'description' => $v->product->description, 'color' => $v->color, 'capacity_gb' => $v->product->capacity_gb, 'quantity' => $quantity, 'printing' => $printing[$id] ?? null];
            }
            $request = QuoteRequest::create(['request_key' => $key, 'payload_hash' => $hash, 'customer' => $customer, 'items' => $items, 'message' => $message, 'status' => 'received', 'has_seller' => $hasSeller, 'seller_name' => $sellerName]);
            app(AdminAuditLogger::class)->record('quote_request.received', 'quote_request', $request->uuid, after: ['lines' => count($items)]);
            app(CommerceNotifier::class)->queue('request.received', $request->uuid, 'Se recibió una solicitud de cotización con '.count($items).' variantes.');

            return $request;
        });
    }

    public function convert(QuoteRequest $request, int $actorId, ?string $unusedLegacySeller = null): Quote
    {
        return DB::transaction(function () use ($request, $actorId) {
            DB::table('commerce_locks')->where('id', 1)->lockForUpdate()->first();
            $request = QuoteRequest::lockForUpdate()->findOrFail($request->id);
            if ($request->quote_id) {
                return $request->quote;
            }
            abort_if($request->status === 'discarded', 409);
            foreach ($request->items as $item) {
                $variant = ProductVariant::with('product.category')->find($item['variant_id']);
                if ($problem = CommercialAvailability::problem($variant, $item['quantity'])) {
                    throw ValidationException::withMessages(['cart' => $item['sku'].': '.$problem.' La solicitud original no se ha modificado.']);
                }
            }
            $seller = $request->seller;
            if ($seller && ! $seller->is_active) {
                throw ValidationException::withMessages(['seller_id' => 'El vendedor confirmado está inactivo. Revisa su asociación.']);
            }
            // Never deduplicate clients by a similar name or overwrite an existing contact.
            $client = CommercialClient::create(collect($request->customer)->only(['name', 'email', 'phone', 'company', 'country'])->all());
            $data = ['revision' => 1, 'client_id' => $client->id, 'customer_name' => $request->customer['name'], 'customer_email' => $request->customer['email'], 'customer_phone' => $request->customer['phone'] ?? null, 'customer_company' => $request->customer['company'] ?? null, 'customer_country' => $request->customer['country'] ?? null, 'seller' => $seller?->name ?? $request->seller_name ?? '', 'seller_id' => $seller?->id, 'terms' => CommercialSetting::values()['terms'] ?? '', 'validity_days' => 30, 'tax_basis_points' => 1600, 'shipping' => '0'];
            $data['items'] = array_map(function ($i) {
                $proposal = app(CommercialPricing::class)->proposal(ProductVariant::findOrFail($i['variant_id']), $i['quantity']);

                return ['variant_id' => $i['variant_id'], 'quantity' => $i['quantity'], 'unit_price' => $proposal['price'] ?? '0',
                    'pricing_action' => $proposal['available'] ? 'automatic' : 'keep', 'pricing_fingerprint' => $proposal['fingerprint'] ?? null,
                    'printing_price' => '0', 'discount' => '0', 'printing' => $i['printing'] ?? null];
            }, $request->items);
            $quote = app(QuoteService::class)->save($data, $actorId);
            $quote->update(['seller_original_name' => $request->seller_name]);
            // Preserve the customer's original selection, including descriptions before later imports.
            foreach ($quote->items as $i => $item) {
                $source = $request->items[$i];
                $item->update(['product' => collect($source)->except(['variant_id', 'quantity', 'printing'])->all()]);
            }
            $request->update(['status' => 'converted', 'quote_id' => $quote->id, 'revision' => $request->revision + 1]);
            app(AdminAuditLogger::class)->record('quote_request.converted', 'quote_request', $request->uuid, after: ['quote' => $quote->uuid], actorId: $actorId);

            return $quote;
        });
    }

    public function assignSeller(QuoteRequest $request, ?int $sellerId, int $revision, int $actorId): void
    {
        DB::transaction(function () use ($request, $sellerId, $revision, $actorId) {
            DB::table('commerce_locks')->where('id', 1)->lockForUpdate()->first();
            $request = QuoteRequest::lockForUpdate()->findOrFail($request->id);
            abort_if($request->quote_id || $request->revision !== $revision, 409, 'La solicitud cambió. Abre su estado actualizado.');
            if ($sellerId !== null && ! CommercialSeller::whereKey($sellerId)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['seller_id' => 'Selecciona un vendedor activo o deja la solicitud sin asociación.']);
            }
            $before = $request->seller_id;
            $request->update(['seller_id' => $sellerId, 'seller_confirmed_by' => $actorId, 'seller_confirmed_at' => now(), 'revision' => $request->revision + 1]);
            app(AdminAuditLogger::class)->record('quote_request.seller_assigned', 'quote_request', $request->uuid, before: ['seller_id' => $before], after: ['seller_id' => $sellerId], actorId: $actorId);
        });
    }
}
