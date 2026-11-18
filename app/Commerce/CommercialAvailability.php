<?php

namespace App\Commerce;

use App\Models\ProductVariant;

final class CommercialAvailability
{
    public const MINIMUM = 50;

    public static function problem(?ProductVariant $variant, mixed $quantity): ?string
    {
        if (! $variant || ! $variant->provider_active || ! $variant->is_enabled || ! $variant->product->isPublic()) {
            return 'Esta variante ya no está disponible para cotizar. Revisa tu selección.';
        }
        if ($variant->stock < self::MINIMUM) {
            return 'Este producto no cuenta actualmente con las 50 piezas mínimas disponibles para cotizar.';
        }
        if (! is_int($quantity) || $quantity < self::MINIMUM) {
            return 'La cantidad mínima es de 50 piezas por variante.';
        }
        if ($quantity > $variant->stock) {
            return 'La cantidad supera las existencias. El máximo disponible para esta variante es de '.number_format($variant->stock).' piezas.';
        }

        return null;
    }
}
