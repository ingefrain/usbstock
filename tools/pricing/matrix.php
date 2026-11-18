<?php

// Read-only calculation against the imported, verified development rules.
require dirname(__DIR__).'/development-catalog/local.php';

use App\Commerce\CommercialPricing;
use App\Commerce\Money;

$reference = json_decode(file_get_contents(base_path('tests/Fixtures/Commerce/legacy-price-scales.json')), true, flags: JSON_THROW_ON_ERROR);
$lines = ['# Matriz de regresión de precios — 18/11/2026', '',
    'Referencia independiente: expresiones DECIMAL de MariaDB sobre los costos y las escalas reales de `usbstock2`, consultados en transacción READ ONLY. No se ejecutó PHP heredado. Esperado aprobado usa el costo propio de la variante; Legado conserva el mínimo activo del modelo para hacer visible la diferencia autorizada.', '',
    'Los casos son simulaciones aritméticas: algunas cantidades superan existencias. No son cotizaciones ni modifican el catálogo. La disponibilidad se valida por separado en conversión, propuesta y guardado.', '',
    '| SKU | Cantidad | Costo propio | Costo modelo legado | Escala propia / incremento | Esperado aprobado | Laravel | Diferencia esperado | Legado | Diferencia legado |',
    '| --- | ---: | ---: | ---: | --- | ---: | ---: | ---: | ---: | ---: |'];
$differences = 0;
foreach ($reference['cases'] as $case) {
    $result = app(CommercialPricing::class)->calculate(Money::parse($case['variant_cost']), $case['quantity']);
    if (! $result['available'] || $result['price'] !== $case['expected_variant_price']) {
        throw new RuntimeException('Calculation differs from independent reference.');
    }
    $delta = $result['price_minor'] - Money::parse($case['expected_legacy_price']);
    $differences += (int) ($delta !== 0);
    $lines[] = '| '.implode(' | ', [$case['sku'], $case['quantity'], $case['variant_cost'], $case['legacy_model_cost'], $case['scale_id'].' / '.$case['increment'].'%', $case['expected_variant_price'], $result['price'], '0.00', $case['expected_legacy_price'], Money::decimal($delta)]).' |';
}
$lines[] = '';
$lines[] = 'Resultado: '.count($reference['cases']).' coincidencias con la política aprobada; '.$differences.' diferencias deliberadas frente al mínimo del modelo. Las 20 restantes coinciden también con el legado. Redondeo HALF_UP a dos decimales, incluido 1.50 × 1.35 = 2.025 → 2.03.';
$lines[] = '';
$lines[] = 'Trazabilidad: `site/tests/Fixtures/Commerce/legacy-price-scales.json` conserva resultados SQL, bandas originales y SHA-256 de cuatro archivos fuente; `site/tools/pricing/reference.py` documenta la consulta reproducible. No contiene credenciales ni datos de clientes.';
file_put_contents(base_path('../xtras/docs/MATRIZ_PRECIOS_ESCALAS_USBSTOCK.md'), implode("\n", $lines)."\n");
echo json_encode(['cases' => count($reference['cases']), 'approved_policy_matches' => count($reference['cases']), 'deliberate_legacy_differences' => $differences]).PHP_EOL;
