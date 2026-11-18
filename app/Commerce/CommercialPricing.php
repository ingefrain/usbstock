<?php

namespace App\Commerce;

use App\Models\CommercialPriceScale;
use App\Models\ProductVariant;
use App\Models\QuoteItem;
use App\Support\AdminAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CommercialPricing
{
    public const ALGORITHM = 'legacy_quantity_cost_v1';

    public function save(array $input, int $actor, ?CommercialPriceScale $scale = null): CommercialPriceScale
    {
        $data = Validator::make($input, [
            'name' => 'required|string|max:120', 'lower' => ['required', 'regex:/^(0|[1-9][0-9]{0,8})(\.\d{1,2})?$/'],
            'upper' => ['nullable', 'regex:/^(0|[1-9][0-9]{0,8})(\.\d{1,2})?$/'], 'increment' => ['required', 'regex:/^(0|[1-9][0-9]{0,3})(\.\d{1,2})?$/'],
            'is_active' => 'required|boolean', 'revision' => 'required|integer|min:1', 'source_reference' => 'required|string|min:10|max:500',
        ])->validate();
        $values = ['name' => $data['name'], 'lower_minor' => Money::parse($data['lower']), 'upper_minor' => filled($data['upper'] ?? null) ? Money::parse($data['upper']) : null,
            'increment_basis_points' => Money::parse($data['increment']), 'is_active' => (bool) $data['is_active'], 'source_reference' => $data['source_reference']];
        if ($values['upper_minor'] !== null && $values['upper_minor'] < $values['lower_minor']) {
            throw ValidationException::withMessages(['upper' => 'El límite superior debe ser igual o mayor que el inferior.']);
        }
        if ($values['increment_basis_points'] > 100000) {
            throw ValidationException::withMessages(['increment' => 'El incremento máximo es 1000%.']);
        }

        return DB::transaction(function () use ($data, $values, $scale, $actor) {
            DB::table('commerce_locks')->where('id', 1)->lockForUpdate()->first();
            if ($scale) {
                $scale = CommercialPriceScale::lockForUpdate()->findOrFail($scale->id);
                abort_unless($scale->revision === (int) $data['revision'], 409, 'La escala cambió. Abre su versión actual.');
            }
            if ($values['is_active'] && CommercialPriceScale::where('is_active', true)->when($scale, fn ($q) => $q->where('id', '<>', $scale->id))
                ->where('lower_minor', '<=', $values['upper_minor'] ?? PHP_INT_MAX)->where(fn ($q) => $q->whereNull('upper_minor')->orWhere('upper_minor', '>=', $values['lower_minor']))->exists()) {
                throw ValidationException::withMessages(['lower' => 'Los límites se solapan con una escala activa. Los extremos son inclusivos; una banda consecutiva empieza un centavo después.']);
            }
            $before = $scale?->only(array_merge(array_keys($values), ['revision']));
            $scale ??= new CommercialPriceScale;
            $scale->fill($values + ['revision' => $scale->exists ? $scale->revision + 1 : 1])->save();
            app(AdminAuditLogger::class)->record('pricing.scale_saved', 'price_scale', $scale->uuid, before: $before, after: $scale->only(array_merge(array_keys($values), ['revision'])), actorId: $actor);

            return $scale;
        });
    }

    public function calculate(int $cost, int $quantity): array
    {
        if ($quantity < 50 || $quantity > 1000000) {
            return $this->unavailable('La cantidad debe ser de 50 a 1,000,000 piezas.');
        }
        if ($cost <= 0 || $cost > 100000000) {
            return $this->unavailable('El costo del proveedor no es válido para una propuesta automática.');
        }
        $base = $cost * $quantity;
        $matches = CommercialPriceScale::where('is_active', true)->where('lower_minor', '<=', $base)->where(fn ($q) => $q->whereNull('upper_minor')->orWhere('upper_minor', '>=', $base))->limit(2)->get();
        if ($matches->count() !== 1) {
            return $this->unavailable($matches->isEmpty() ? 'No hay una escala activa para este importe. Captura manual disponible.' : 'Hay escalas ambiguas. No se propone un precio.');
        }
        $scale = $matches->sole();
        $price = intdiv($cost * (10000 + $scale->increment_basis_points) + 5000, 10000);
        if ($price > 100000000) {
            return $this->unavailable('El precio calculado supera el límite de operación.');
        }
        $basis = ['algorithm' => self::ALGORITHM, 'cost_minor' => $cost, 'quantity' => $quantity, 'base_minor' => $base, 'price_minor' => $price,
            'scale_uuid' => $scale->uuid, 'scale_revision' => $scale->revision, 'scale_name' => $scale->name, 'lower_minor' => $scale->lower_minor, 'upper_minor' => $scale->upper_minor,
            'increment_basis_points' => $scale->increment_basis_points, 'source_reference' => $scale->source_reference, 'rounding' => 'HALF_UP_2'];

        return ['available' => true, 'price_minor' => $price, 'price' => Money::decimal($price), 'basis' => $basis, 'fingerprint' => hash('sha256', json_encode($basis, JSON_THROW_ON_ERROR))];
    }

    public function proposal(ProductVariant $variant, int $quantity): array
    {
        $context = ['cost_minor' => $variant->supplier_price_minor, 'quantity' => $quantity, 'sku' => $variant->sku];
        if (! $variant->provider_active || ! $variant->is_enabled || $quantity > $variant->stock) {
            return $this->unavailable('La variante no está disponible para esa cantidad.') + ['context' => $context];
        }
        // Approved on 2026-11-18: use the quoted variant's own current supplier cost,
        // rather than the legacy view's minimum cost across sibling variants.
        $result = $this->calculate($variant->supplier_price_minor, $quantity);
        if ($result['available']) {
            $result['basis']['variant_id'] = $variant->id;
            $result['basis']['sku'] = $variant->sku;
            $result['basis']['cost_source'] = 'current_supplier_variant';
            $result['fingerprint'] = hash('sha256', json_encode($result['basis'], JSON_THROW_ON_ERROR));
        }

        return $result + ['context' => $context];
    }

    public function linePricing(ProductVariant $variant, array $line, ?QuoteItem $previous, int $actor): ?array
    {
        $price = Money::parse($line['unit_price']);
        $quantity = (int) $line['quantity'];
        if (($line['pricing_action'] ?? 'keep') === 'automatic') {
            $proposal = $this->proposal($variant, $quantity);
            if (! $proposal['available']) {
                throw ValidationException::withMessages(['items' => $variant->sku.': '.$proposal['reason']]);
            }
            abort_unless(hash_equals($proposal['fingerprint'], $line['pricing_fingerprint'] ?? '') && $price === $proposal['price_minor'], 409, 'El costo, cantidad o escala cambió. Consulta y acepta una propuesta actualizada.');

            return ['mode' => 'automatic', 'basis' => $proposal['basis'], 'actor_id' => $actor, 'applied_at' => now()->toIso8601String()];
        }
        if ($previous && $previous->unit_minor === $price && $previous->quantity === $quantity) {
            return $previous->pricing;
        }
        $manual = $previous && $previous->unit_minor !== $price;
        if ($manual && ($previous->pricing['basis'] ?? null) && blank($line['pricing_reason'] ?? null)) {
            throw ValidationException::withMessages(['items' => 'Indica el motivo del ajuste manual de '.$variant->sku.'.']);
        }

        return ['mode' => $previous && ! $manual ? 'retained' : 'manual', 'basis' => $previous?->pricing['basis'] ?? null,
            'reason' => $line['pricing_reason'] ?? null, 'actor_id' => $actor, 'applied_at' => now()->toIso8601String()];
    }

    private function unavailable(string $reason): array
    {
        return ['available' => false, 'reason' => $reason];
    }
}
