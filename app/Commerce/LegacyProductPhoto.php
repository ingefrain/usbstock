<?php

namespace App\Commerce;

use App\Models\Document;
use App\Models\Product;
use App\Models\User;
use App\Services\Documents\DocumentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Explicit, verified local mapping only. No discovery by filename similarity or remote URLs. */
final class LegacyProductPhoto
{
    public function attach(Product $product, array $mapping, string $sourceDirectory, User $actor): Document
    {
        $fail = fn () => throw ValidationException::withMessages(['image' => 'El mapeo local o su integridad no se pudo verificar.']);
        if (($mapping['source'] ?? null) !== 'codexv0' || ($mapping['model'] ?? null) !== $product->external_key
            || ! is_int($mapping['legacy_variant_id'] ?? null) || $mapping['legacy_variant_id'] < 1
            || ! preg_match('/\A[a-f0-9]{64}\z/', $mapping['sha256'] ?? '')
            || ! $product->variants()->where('sku', $mapping['sku'] ?? '')->exists()) {
            $fail();
        }
        $root = realpath($sourceDirectory);
        $file = $root ? realpath($root.DIRECTORY_SEPARATOR.$mapping['legacy_variant_id'].'.jpg') : false;
        if (! $root || ! $file || dirname($file) !== $root || ! is_file($file) || filesize($file) > 5 * 1024 * 1024) {
            $fail();
        }
        $bytes = file_get_contents($file);
        $info = @getimagesizefromstring($bytes);
        if (! hash_equals($mapping['sha256'], hash('sha256', $bytes)) || ! $info || $info[2] !== IMAGETYPE_JPEG) {
            $fail();
        }

        return DB::transaction(function () use ($product, $mapping, $actor, $bytes, $file, $fail) {
            $product = Product::lockForUpdate()->findOrFail($product->id);
            if ($existing = $product->image) {
                if (($existing->metadata['legacy_source_sha256'] ?? null) === $mapping['sha256']
                    && ($existing->metadata['legacy_sku'] ?? null) === $mapping['sku']) {
                    return $existing;
                }
                throw ValidationException::withMessages(['image' => 'El producto ya tiene fotografía. Se requiere revisión explícita para sustituirla.']);
            }
            $path = 'legacy-originals/codexv0/'.$mapping['sha256'].'.jpg';
            $disk = Storage::disk('local');
            if ($disk->exists($path)) {
                if (! hash_equals($mapping['sha256'], hash('sha256', $disk->get($path)))) {
                    $fail();
                }
            } elseif (! $disk->put($path, $bytes)) {
                $fail();
            }

            // Process the preserved copy, not a legacy path that could change between checks.
            return app(DocumentService::class)->upload(new UploadedFile($disk->path($path), basename($file), 'image/jpeg', null, true), $product, $actor, Product::IMAGE_CATEGORY,
                ['title' => $product->external_key.' · fotografía de referencia'],
                ['legacy_source' => 'codexv0', 'legacy_variant_id' => $mapping['legacy_variant_id'], 'legacy_sku' => $mapping['sku'], 'legacy_source_sha256' => $mapping['sha256'], 'original_private_path' => $path]);
        });
    }
}
