<?php

namespace App\Commerce;

use App\Models\CommercialSetting;
use App\Models\ProductVariant;
use App\Models\Quote;
use App\Models\QuoteRequest;
use App\Support\AdminAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PublicQuoteRequests
{
    public function submit(array $customer, array $cart, ?string $message, string $key): QuoteRequest
    {
        $hash = hash('sha256', json_encode([$customer, $cart, $message], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($customer, $cart, $message, $key, $hash) {
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
                if (! $v || ! $v->provider_active || ! $v->is_enabled || ! $v->product->isPublic() || $quantity < 50 || $quantity > $v->stock) {
                    throw ValidationException::withMessages(['cart' => 'Cambió la disponibilidad de una variante. Revisa tu selección.']);
                }
                $items[] = ['variant_id' => $v->id, 'sku' => $v->sku, 'name' => $v->product->name, 'description' => $v->product->description, 'color' => $v->color, 'capacity_gb' => $v->product->capacity_gb, 'quantity' => $quantity];
            }
            $request = QuoteRequest::create(['request_key' => $key, 'payload_hash' => $hash, 'customer' => $customer, 'items' => $items, 'message' => $message, 'status' => 'received']);
            app(AdminAuditLogger::class)->record('quote_request.received', 'quote_request', $request->uuid, after: ['lines' => count($items)]);
            app(CommerceNotifier::class)->queue('request.received', $request->uuid, 'Se recibió una solicitud de cotización con '.count($items).' variantes.');

            return $request;
        });
    }

    public function convert(QuoteRequest $request, int $actorId, string $seller): Quote
    {
        return DB::transaction(function () use ($request, $actorId, $seller) {
            DB::table('commerce_locks')->where('id', 1)->lockForUpdate()->first();
            $request = QuoteRequest::lockForUpdate()->findOrFail($request->id);
            if ($request->quote_id) {
                return $request->quote;
            }
            abort_if($request->status === 'discarded', 409);
            $data = ['revision' => 1, 'customer_name' => $request->customer['name'], 'customer_email' => $request->customer['email'], 'customer_phone' => $request->customer['phone'], 'seller' => $seller, 'terms' => CommercialSetting::values()['terms'] ?? '', 'validity_days' => 30, 'tax_basis_points' => 1600, 'shipping' => '0'];
            $data['items'] = array_map(fn ($i) => ['variant_id' => $i['variant_id'], 'quantity' => $i['quantity'], 'unit_price' => '0', 'printing_price' => '0', 'discount' => '0', 'printing' => null], $request->items);
            $quote = app(QuoteService::class)->save($data, $actorId);
            // Preserve the customer's original selection, including descriptions before later imports.
            foreach ($quote->items as $i => $item) {
                $source = $request->items[$i];
                $item->update(['product' => collect($source)->except(['variant_id', 'quantity'])->all()]);
            }
            $request->update(['status' => 'converted', 'quote_id' => $quote->id]);
            app(AdminAuditLogger::class)->record('quote_request.converted', 'quote_request', $request->uuid, after: ['quote' => $quote->uuid], actorId: $actorId);

            return $quote;
        });
    }
}
