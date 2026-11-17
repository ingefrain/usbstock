<?php

namespace Tests\Feature;

use App\Commerce\QuoteCalculator;
use Tests\TestCase;

class LegacyPricingRegressionTest extends TestCase
{
    // Frozen, synthetic scenarios reconstructed from cotizaciones/acciones.php:2592-2616.
    public function test_manual_quote_flow_matches_legacy_scenarios_without_automatic_margins(): void
    {
        $cases = [
            [[[50, 2000, 200, 10000]], 5000, 122600],
            [[[1, 5, 0, 0]], 0, 6],
            [[[100, 8500, 650, 15000]], 25000, 1071400],
            [[[50, 2000, 200, 10000], [100, 2500, 0, 5000]], 15000, 417600],
            [[[25, 5555, 150, 2500]], 8000, 170945],
            [[[1000, 9876543, 650, 15000]], 25000, 11457553880],
        ];
        foreach ($cases as [$rows, $shipping, $expected]) {
            $items = array_map(fn ($r) => array_combine(['quantity', 'unit_minor', 'printing_minor', 'discount_minor'], $r), $rows);
            // The legacy DB aggregates quantity*(unit+printing); tax is on that aggregate,
            // discounts follow tax, and shipping is added last.
            $legacySubtotal = array_sum(array_map(fn ($r) => $r[0] * (($r[1] + $r[2]) / 100), $rows));
            $legacyDiscount = array_sum(array_column($rows, 3)) / 100;
            $legacyTotal = (int) round(($legacySubtotal + $legacySubtotal * .16 - $legacyDiscount + $shipping / 100) * 100, 0, PHP_ROUND_HALF_UP);
            $this->assertSame($expected, $legacyTotal);
            $this->assertSame($legacyTotal, app(QuoteCalculator::class)->calculate($items, 1600, $shipping)['total']);
        }
    }

    public function test_legacy_scale_algorithms_diverge_at_confirmed_boundaries(): void
    {
        $bands = [[0, 3000, 35], [3000, 10000, 35], [10000, 20000, 29], [20000, 1000000000, 22]];
        $helper = static function (int $cost, int $quantity) use ($bands): int {
            foreach ($bands as [$low, $high, $rate]) {
                if ($quantity * $cost >= $low * 100 && $quantity * $cost <= $high * 100) {
                    return (int) round($cost * (100 + $rate) / 100);
                }
            }

            return $cost;
        };
        $public = static function (int $cost, int $quantity) use ($bands): int {
            foreach ($bands as $i => [$low, $high, $rate]) {
                $price = (int) round($cost * (100 + $rate) / 100);
                $min = max(50, (int) ceil($low * 100 / $price));
                $max = isset($bands[$i + 1]) ? (int) ceil($bands[$i + 1][0] * 100 / ($cost * (100 + $bands[$i + 1][2]) / 100)) - 1 : PHP_INT_MAX;
                if ($quantity >= $min && $quantity <= $max) {
                    return $price;
                }
            }
            throw new \LogicException('No range in synthetic legacy fixture.');
        };
        foreach ([[50, 2700, 2700], [387, 2700, 2700], [388, 2700, 2580], [500, 2700, 2580], [501, 2580, 2580], [820, 2580, 2440], [1000, 2580, 2440], [1001, 2440, 2440]] as [$quantity, $helperPrice, $publicPrice]) {
            $this->assertSame($helperPrice, $helper(2000, $quantity));
            $this->assertSame($publicPrice, $public(2000, $quantity));
        }
    }
}
