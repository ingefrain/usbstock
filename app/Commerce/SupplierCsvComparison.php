<?php

namespace App\Commerce;

use Illuminate\Validation\ValidationException;

final class SupplierCsvComparison
{
    public function __construct(private SupplierCsv $csv) {}

    public function compare(string $baseline, string $current): array
    {
        $old = $this->parse($baseline);
        $new = $this->parse($current);
        $report = ['version' => 1, 'baseline' => $this->summary($old), 'current' => $this->summary($new),
            'comparable' => $old['errors'] === [] && $new['errors'] === [], 'changes' => null];
        if (! $report['comparable']) {
            return $report;
        }
        $before = $old['rows'];
        $after = $new['rows'];
        $changes = ['new_skus' => array_values(array_diff(array_keys($after), array_keys($before))),
            'missing_skus' => array_values(array_diff(array_keys($before), array_keys($after))),
            'stock_changed_skus' => [], 'cost_changed_skus' => [], 'description_changed_skus' => [], 'large_cost_change_skus' => []];
        foreach (array_intersect_key($after, $before) as $sku => $row) {
            foreach (['stock' => 'stock_changed_skus', 'supplier_price_minor' => 'cost_changed_skus', 'supplier_description' => 'description_changed_skus'] as $field => $list) {
                if ($row[$field] !== $before[$sku][$field]) {
                    $changes[$list][] = $sku;
                }
            }
            if (abs($row['supplier_price_minor'] - $before[$sku]['supplier_price_minor']) * 100 > max(1, $before[$sku]['supplier_price_minor']) * config('commerce.sync.max_price_change_percent')) {
                $changes['large_cost_change_skus'][] = $sku;
            }
        }
        $previousStock = $report['baseline']['total_stock'];
        $currentStock = $report['current']['total_stock'];
        $counts = array_map('count', $changes);
        $stockDrop = $previousStock > 0 && ($previousStock - $currentStock) * 100 > $previousStock * config('commerce.sync.max_stock_drop_percent');
        $missing = count($before) * config('commerce.sync.max_missing_percent') < $counts['missing_skus'] * 100;
        $report['changes'] = ['counts' => $counts, 'skus' => $changes, 'total_stock_delta' => $currentStock - $previousStock,
            'stock_drop_alert' => $stockDrop, 'missing_alert' => $missing,
            'suspicious' => $stockDrop || $missing || $counts['large_cost_change_skus'] > 0,
            'same_catalog' => $before === $after];

        return $report;
    }

    private function parse(string $bytes): array
    {
        try {
            return $this->csv->parse($bytes);
        } catch (ValidationException) {
            return ['rows' => [], 'errors' => [['message' => 'Archivo inválido; comparación bloqueada.']], 'sha256' => hash('sha256', $bytes)];
        }
    }

    private function summary(array $data): array
    {
        return ['sha256' => $data['sha256'], 'valid' => $data['errors'] === [], 'received' => $data['received_count'] ?? 0,
            'variants' => count($data['rows']), 'models' => count(array_unique(array_column($data['rows'], 'model'))),
            'empty_descriptions' => $data['empty_descriptions'] ?? 0, 'duplicates_consolidated' => $data['duplicates_consolidated'] ?? 0,
            'warnings' => count($data['warnings'] ?? []), 'errors' => $data['error_count'] ?? count($data['errors']),
            'total_stock' => array_sum(array_column($data['rows'], 'stock'))];
    }
}
