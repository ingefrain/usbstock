<?php

namespace App\Commerce;

use Illuminate\Validation\ValidationException;

final class QuoteCalculator
{
    // Integer cents, aggregate HALF_UP tax, legacy discount after tax, untaxed shipping.
    public function calculate(array $lines, int $taxBasisPoints, int $shipping): array
    {
        $invalid = fn () => throw ValidationException::withMessages(['items' => 'Importes o cantidades fuera de los límites permitidos.']);
        if (count($lines) < 1 || count($lines) > 100 || $taxBasisPoints < 0 || $taxBasisPoints > 10000 || $shipping < 0 || $shipping > 100000000) {
            $invalid();
        }
        $subtotal = $discount = 0;
        $lineTotals = [];
        foreach ($lines as $line) {
            foreach (['quantity', 'unit_minor', 'printing_minor', 'discount_minor'] as $key) {
                if (! isset($line[$key]) || ! is_int($line[$key]) || $line[$key] < 0) {
                    $invalid();
                }
            }
            if ($line['quantity'] < 1 || $line['quantity'] > 1000000 || max($line['unit_minor'], $line['printing_minor']) > 100000000) {
                $invalid();
            }
            $amount = $line['quantity'] * ($line['unit_minor'] + $line['printing_minor']);
            $lineTotals[] = $amount;
            if ($line['discount_minor'] > $amount) {
                $invalid();
            }
            $subtotal += $amount;
            $discount += $line['discount_minor'];
            if ($subtotal > 100000000000) {
                $invalid();
            }
        }
        $tax = intdiv($subtotal * $taxBasisPoints + 5000, 10000);

        return ['subtotal' => $subtotal, 'discount' => $discount, 'tax' => $tax, 'shipping' => $shipping, 'total' => $subtotal + $tax - $discount + $shipping, 'currency' => 'MXN', 'line_totals' => $lineTotals];
    }
}
