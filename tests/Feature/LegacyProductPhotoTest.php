<?php

namespace Tests\Feature;

use App\Commerce\LegacyProductPhoto;
use App\Commerce\ProductThumbnail;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LegacyProductPhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_mapping_preserves_original_and_produces_idempotent_webp(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $disk->put('source/123.jpg', file_get_contents(base_path('prototypes/ux-ui/assets/373.jpg')));
        $hash = hash('sha256', $disk->get('source/123.jpg'));
        $product = Product::create(['external_key' => 'USB1', 'slug' => 'usb1', 'supplier_description' => 'Prueba']);
        $product->variants()->create(['sku' => 'USB1-AZ', 'color_code' => 'AZ', 'stock' => 100, 'supplier_price_minor' => 0, 'supplier_description' => 'Prueba']);
        $actor = User::factory()->create();
        $mapping = ['source' => 'codexv0', 'model' => 'USB1', 'sku' => 'USB1-AZ', 'legacy_variant_id' => 123, 'sha256' => $hash];
        $service = app(LegacyProductPhoto::class);
        $document = $service->attach($product, $mapping, $disk->path('source'), $actor);
        $this->assertSame('image/webp', $document->mime_type);
        $this->assertSame($hash, hash('sha256', $disk->get($document->metadata['original_private_path'])));
        $this->assertSame($document->id, $service->attach($product, $mapping, $disk->path('source'), $actor)->id);
        app(ProductThumbnail::class)->response($document);
        $info = getimagesizefromstring($disk->get('product-thumbnails/v1/'.$document->checksum.'.webp'));
        $this->assertLessThanOrEqual(480, max($info[0], $info[1]));
        foreach ([['sku' => 'USB2-AZ'], ['model' => 'USB2'], ['legacy_variant_id' => '../123'], ['sha256' => str_repeat('0',64)], ['legacy_variant_id' => 124]] as $invalid) {
            try {
                $service->attach($product, array_replace($mapping, $invalid), $disk->path('source'), $actor);
                $this->fail('Unverified mapping accepted');
            } catch (ValidationException) {
                $this->assertDatabaseCount('documents', 1);
            }
        }
        $this->assertSame($hash, hash('sha256', $disk->get('source/123.jpg')));
    }
}
