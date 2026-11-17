<?php

namespace Tests\Feature;

use App\Commerce\QuotePhotos;
use App\Commerce\QuoteService;
use App\Commerce\SupplierSync;
use App\Models\CommercialSetting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\QuoteVersion;
use App\Services\Documents\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class QuotePhotosTest extends TestCase
{
    use InteractsWithBaseAdmin, RefreshDatabase;

    public function test_quote_images_override_catalog_and_history_retains_original_bytes(): void
    {
        Storage::fake('local');
        $actor = $this->adminUser();
        $this->actingAsAdmin($actor);
        $sync = app(SupplierSync::class);
        $sync->apply($sync->preview("SKU,PIEZAS,DESCRIPCION,PRECIO_SIN_IVA\nPRUEBA-A,100,Descripción de prueba,10", 'test.csv', $actor->id), $actor->id);
        $product = Product::sole();
        $variant = ProductVariant::sole();
        $catalogImage = app(DocumentService::class)->upload(UploadedFile::fake()->image('catalogo.jpg', 400, 200), $product, $actor, 'product.image');
        CommercialSetting::create(['id' => 1, 'data' => ['issuer_name' => 'Emisor ficticio', 'issuer_contact' => 'test@example.test']]);
        $data = ['revision' => 1, 'customer_name' => 'Cliente ficticio', 'customer_email' => 'client@example.test', 'seller' => 'Asesor ficticio', 'terms' => 'Condiciones de prueba', 'validity_days' => 30, 'tax_basis_points' => 1600, 'shipping' => '50', 'items' => [['variant_id' => $variant->id, 'quantity' => 50, 'unit_price' => '20', 'printing_price' => '2', 'discount' => '100']]];
        $quote = app(QuoteService::class)->save($data, $actor->id);
        $photos = app(QuotePhotos::class);
        $this->assertSame('catalog', $photos->sources($quote)[0]['source']);
        $this->post(route('admin.quotes.photo', $quote), ['revision' => 1, 'variant_id' => $variant->id, 'image' => UploadedFile::fake()->image('cotizacion.png', 200, 400)])->assertRedirect()->assertSessionHasNoErrors();
        $quote->refresh();
        $this->assertSame(2, $quote->revision);
        $this->assertSame('quote', $photos->sources($quote)[0]['source']);
        $this->assertSame($catalogImage->id, $product->fresh()->image->id);
        $preview = $photos->preview($quote);
        $info = getimagesizefromstring($preview['items'][0]['photo_bytes']);
        $this->assertSame([600, 600], [$info[0], $info[1]]);
        $this->get(route('admin.quotes.preview', ['quote' => $quote, 'concept' => 'b']))->assertOk()->assertSee('NO EMITIDA');
        $version = app(QuoteService::class)->issue($quote, 2, (string) Str::uuid(), $actor->id);
        $photo = $version->snapshot['items'][0]['photo'];
        $frozen = Storage::disk('local')->get($photo['path']);
        $this->assertSame('quote', $photo['source']);
        $this->assertSame(hash('sha256', $frozen), $photo['sha256']);
        $this->assertSame(122600, $version->snapshot['totals']['total']);
        $pdf = Storage::disk('local')->get($version->path);
        $this->post(route('admin.quotes.photo', $quote), ['revision' => 3, 'variant_id' => $variant->id, 'remove' => 1])->assertStatus(409);
        $this->get(route('admin.quotes.preview', ['quote' => $quote, 'concept' => 'b']))->assertStatus(409);
        app(QuoteService::class)->revise($quote->fresh(), 3, $actor->id);
        $this->post(route('admin.quotes.photo', $quote), ['revision' => 4, 'variant_id' => $variant->id, 'remove' => 1])->assertRedirect();
        $this->assertSame('catalog', $photos->sources($quote)[0]['source']);
        $this->assertSame($frozen, Storage::disk('local')->get($photo['path']));
        $this->assertSame($pdf, Storage::disk('local')->get($version->path));
        $this->assertSame($photo, QuoteVersion::sole()->snapshot['items'][0]['photo']);
        $this->actingAsAdmin($this->userWithRole('operator'))->post(route('admin.quotes.photo', $quote), ['revision' => 5, 'variant_id' => $variant->id, 'remove' => 1])->assertForbidden();
    }
}
