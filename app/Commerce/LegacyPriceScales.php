<?php

namespace App\Commerce;

use App\Models\CommercialPriceScale;
use App\Support\AdminAuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class LegacyPriceScales
{
    public function import(array $reference, int $actor): int
    {
        // This importer is deliberately limited to the exact audited legacy profile.
        $bands = $reference['bands'] ?? [];
        $expected = [
            ['id' => 1, 'lower' => 0, 'upper' => 3000, 'increment' => 35],
            ['id' => 2, 'lower' => 3000, 'upper' => 10000, 'increment' => 35],
            ['id' => 3, 'lower' => 10000, 'upper' => 20000, 'increment' => 29],
            ['id' => 4, 'lower' => 20000, 'upper' => 1000000000, 'increment' => 22],
        ];
        if ($bands !== $expected || empty($reference['source_sha256']) || count($reference['cases'] ?? []) !== 26) {
            throw new RuntimeException('The audited legacy reference is required. No rules imported.');
        }

        return DB::transaction(function () use ($bands, $reference, $actor) {
            DB::table('commerce_locks')->where('id', 1)->lockForUpdate()->first();
            $created = 0;
            foreach ($bands as $i => $band) {
                $key = 'usbstock2.productos_escalas:'.$band['id'];
                if (CommercialPriceScale::where('source_key', $key)->exists()) {
                    continue; // Never overwrite manual edits or reactivate an imported rule.
                }
                $source = 'codexv0/cotizaciones/precotizacion-cfg.php + includes/widgets.php; usbstock2.productos_escalas #'.$band['id'].'; auditoría 2026-11-18';
                $scale = app(CommercialPricing::class)->save(['name' => 'Escala heredada '.$band['id'], 'lower' => Money::decimal($band['lower'] * 100 + ($i === 0 ? 0 : 1)),
                    'upper' => $i === count($bands) - 1 ? null : Money::decimal($band['upper'] * 100), 'increment' => (string) $band['increment'],
                    'is_active' => true, 'revision' => 1, 'source_reference' => $source], $actor);
                $scale->update(['source_key' => $key, 'source_metadata' => ['original_band' => $band, 'source_sha256' => $reference['source_sha256'],
                    'normalization' => 'inclusive_first_match_to_disjoint_cents; last_band_fallback_unbounded', 'own_variant_cost_approved' => '2026-11-18']]);
                app(AdminAuditLogger::class)->record('pricing.legacy_imported', 'price_scale', $scale->uuid, after: ['source_reference' => $source, 'source_key' => $key, 'original_band' => $band, 'revision' => $scale->revision], actorId: $actor);
                $created++;
            }

            return $created;
        });
    }
}
