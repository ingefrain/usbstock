<?php

require __DIR__.'/local.php';

use App\Models\CommercialSetting;
use App\Models\Product;
use App\Models\ProductVariant;

$products = [];
foreach (['USB017-AZ', 'USB027-AZ'] as $sku) {
    $variant = ProductVariant::with(['product.image'])->where('sku', $sku)->sole();
    if (! $variant->product->isPublic() || ! $variant->product->image || $variant->stock < 100) {
        throw new RuntimeException('The selected real review product is unavailable.');
    }
    $products[] = ['slug' => $variant->product->slug, 'sku' => $variant->sku, 'variant_uuid' => $variant->uuid];
}
$report = ['environment' => 'local', 'database' => 'usbstock_dev', 'products' => $products,
    'catalog_count' => Product::catalogVisible()->count(), 'without_photo' => Product::catalogVisible()->whereDoesntHave('image')->value('slug'),
    'settings_empty' => CommercialSetting::count() === 0, 'outbound_disabled' => ! array_filter($environment['flags'])];
writeDevelopmentReport('review-context', $report);
echo 'Real development review context ready: '.$report['catalog_count'].' visible models.'.PHP_EOL;
