<?php

require __DIR__.'/local.php';

use App\Http\Controllers\Admin\ProductController;
use App\Models\Product;
use App\Models\SupplierSyncRun;
use App\Services\Documents\DocumentService;
use App\Support\AdminAuditLogger;
use Illuminate\Http\Request;

verifiedDevelopmentBackup($runRoot);
$import = json_decode(file_get_contents($runRoot.'/application.json'), true, flags: JSON_THROW_ON_ERROR);
$run = SupplierSyncRun::where('uuid', $import['uuid'])->sole();
if ($run->status !== 'completed') {
    throw new RuntimeException('The import must be completed.');
}
$models = array_keys($run->summary['model_description_sources']);
$published = $skipped = [];
$changed = 0;
$limit = max(1, (int) ($argv[1] ?? 10));
$remaining = false;
foreach (Product::whereIn('external_key', $models)->get() as $product) {
    if ($product->content_review_required || ! $product->provider_active) {
        $skipped[] = $product->external_key;

        continue;
    }
    if (! $product->is_published) {
        if ($changed >= $limit) {
            $remaining = true;

            continue;
        }
        $request = Request::create('/local-development-review', 'POST', ['is_published' => true]);
        $request->setUserResolver(fn () => $actor);
        // Reuse the administrative validation, authorization, lock, revision and audit.
        app(ProductController::class)->update($request, $product, app(DocumentService::class), app(AdminAuditLogger::class));
        app(AdminAuditLogger::class)->record('development_catalog.published_for_local_review', 'product', $product->uuid,
            after: ['environment' => 'local', 'supplier_run' => $run->uuid, 'source_sha256' => $run->sha256, 'production_authorized' => false], actorId: $actor->id);
        $changed++;
    }
    $published[] = $product->external_key;
}
if ($remaining) {
    echo count($published).' published locally; run publish again to continue.'.PHP_EOL;
    exit;
}
$report = ['environment' => 'local', 'run_uuid' => $run->uuid, 'published_count' => count($published),
    'published_models' => $published, 'skipped_models' => $skipped, 'production_authorized' => false];
writeDevelopmentReport('publication', $report);
echo json_encode(collect($report)->except('published_models')->all(), JSON_PRETTY_PRINT).PHP_EOL;
