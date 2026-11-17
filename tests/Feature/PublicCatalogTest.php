<?php

namespace Tests\Feature;

use App\Commerce\PublicCatalog;
use App\Commerce\SupplierSync;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\Documents\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\Support\CatalogDataset;
use Tests\TestCase;

class PublicCatalogTest extends TestCase
{
    use InteractsWithBaseAdmin, RefreshDatabase;

    public function test_server_first_block_cursor_continuity_no_duplicates_and_last_block(): void
    {
        CatalogDataset::create(100);
        $page = $this->get('/catalogo')->assertOk()->assertSee('24 modelos cargados')->assertSee('rel="next"', false)->assertDontSee('987654');
        $this->assertSame(24, substr_count($page->getContent(), 'data-product-id='));
        $next = '/catalogo/bloques';
        $seen = [];
        $counts = [];
        do {
            $response = $this->getJson($next)->assertOk();
            preg_match_all('/data-product-id="([^"]+)"/', $response->json('html'), $matches);
            $seen = array_merge($seen, $matches[1]);
            $counts[] = $response->json('count');
            $url = $response->json('next');
            $next = $url ? '/catalogo/bloques?'.parse_url($url, PHP_URL_QUERY) : null;
            $this->assertLessThanOrEqual(5, count($counts));
            $this->assertStringNotContainsString('supplier_price_minor', $response->getContent());
        } while ($next);
        $this->assertSame([24, 24, 24, 24, 4], $counts);
        $this->assertCount(100, array_unique($seen));
        $this->assertSame(Product::orderByDesc('id')->pluck('uuid')->all(), $seen);
        $link = $this->getJson('/catalogo/bloques')->json('next');
        $this->get($link)->assertOk()->assertSee('24 modelos cargados');
    }

    public function test_filters_visibility_cursor_validation_and_empty_catalog(): void
    {
        $this->get('/catalogo')->assertOk()->assertSee('Estamos preparando el catálogo')->assertSee('Contactar a USB Stock');
        CatalogDataset::create(30);
        Product::whereKey(30)->update(['is_published' => false]);
        Product::whereKey(29)->update(['provider_active' => false]);
        Product::find(28)->variants()->update(['is_enabled' => false]);
        $category = ProductCategory::create(['name' => 'Oculta', 'slug' => 'oculta', 'is_published' => false]);
        Product::whereKey(27)->update(['product_category_id' => $category->id]);
        $html = $this->getJson('/catalogo/bloques')->assertOk()->json('html');
        foreach ([27, 28, 29, 30] as $id) {
            $this->assertStringNotContainsString('TEST'.str_pad((string) $id, 6, '0', STR_PAD_LEFT), $html);
        }
        $this->getJson('/catalogo/bloques?q=TEST000001&capacity=16&available=1&category=prueba-0')->assertOk()->assertJsonPath('count', 1);
        $this->getJson('/catalogo/bloques?q=%25')->assertOk()->assertJsonPath('count', 0);
        $this->getJson('/catalogo/bloques?cursor=invalid')->assertStatus(422);
        $next = $this->getJson('/catalogo/bloques')->json('next');
        $this->getJson('/catalogo/bloques?'.parse_url($next, PHP_URL_QUERY).'&capacity=16')->assertStatus(422);
        $this->get('/catalogo/categoria/oculta')->assertNotFound();
        $this->get('/catalogo/categoria/prueba-0')->assertOk();
        $this->app->detectEnvironment(fn () => 'production');
        $this->get('/catalogo/categoria/prueba-0')->assertOk()->assertSee('content="index, follow"', false);
        $this->get('/catalogo?category=prueba-0')->assertOk()->assertSee('content="noindex, follow"', false);
        $this->app->detectEnvironment(fn () => 'testing');
        $this->get('/sitemap.xml')->assertOk()->assertSee('/catalogo/categoria/prueba-0')->assertDontSee('/catalogo/categoria/oculta');
        $this->travel(3)->hours();
        $this->getJson('/catalogo/bloques?'.parse_url($next, PHP_URL_QUERY))->assertStatus(409);
        $this->get($next)->assertRedirect('/catalogo');
    }

    public function test_mutation_during_navigation_invalidates_cursor_and_new_ids_do_not_duplicate(): void
    {
        Storage::fake('local');
        CatalogDataset::create(40);
        $first = app(PublicCatalog::class)->block([]);
        CatalogDataset::create(2);
        $second = app(PublicCatalog::class)->block([], $first['next']);
        $this->assertSame(range(16, 1), $second['products']->pluck('id')->all());
        $this->actingAsAdmin();
        $product = Product::find(16);
        $this->put(route('admin.products.update', $product), ['is_published' => 0])->assertRedirect();
        $this->getJson('/catalogo/bloques?cursor='.urlencode($first['next']))->assertStatus(409);
        $this->get(route('shop.product', $product->slug))->assertNotFound();
        $this->getJson('/catalogo/bloques')->assertOk()->assertDontSee('data-product-id="'.$product->uuid.'"', false);
        $next = $this->getJson('/catalogo/bloques')->assertOk()->json('next');
        $sync = app(SupplierSync::class);
        $run = $sync->preview("SKU,PIEZAS,DESCRIPCION,PRECIO_SIN_IVA\nTEST000001-V1,100,Texto ficticio actualizado,9876.54", 'test.csv', null);
        $sync->apply($run, null, false, true);
        $this->getJson('/catalogo/bloques?'.parse_url($next, PHP_URL_QUERY))->assertStatus(409);
    }

    public function test_queries_are_bounded_and_do_not_hydrate_variants(): void
    {
        CatalogDataset::create(1000, 20);
        DB::enableQueryLog();
        $block = app(PublicCatalog::class)->block(['available' => 1, 'capacity' => 16]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(4, count($queries));
        $this->assertCount(24, $block['products']);
        $this->assertFalse($block['products'][0]->relationLoaded('variants'));
        $this->assertArrayNotHasKey('supplier_price_minor', $block['products'][0]->getAttributes());
        $this->assertStringNotContainsString('offset', strtolower(implode(' ', array_column($queries, 'query'))));
    }

    public function test_thumbnails_are_small_private_cached_and_obey_publication(): void
    {
        Storage::fake('local');
        CatalogDataset::create(1);
        $product = Product::sole();
        $document = app(DocumentService::class)->upload(UploadedFile::fake()->image('prueba.png', 1600, 800), $product, $this->adminUser(), 'product.image');
        $url = route('shop.thumbnail', $product->slug);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $cached = 'product-thumbnails/v1/'.$document->checksum.'.webp';
        $bytes = Storage::disk('local')->get($cached);
        $this->assertSame([480, 240], array_slice(getimagesizefromstring($bytes), 0, 2));
        $this->assertLessThan($document->size_bytes, strlen($bytes));
        $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $product->update(['is_published' => false]);
        $this->get($url)->assertNotFound();
    }

    public function test_admin_photo_filter_variant_search_and_permissions(): void
    {
        CatalogDataset::create(2);
        $this->get('/admin/products')->assertRedirect();
        $this->actingAsAdmin();
        $this->get('/admin/products?photo=missing&q=TEST000001-V1')->assertOk()->assertSee('TEST000001')->assertDontSee('TEST000002');
        $this->get('/admin/products?photo=present')->assertOk()->assertSee('Limpiar filtros');
        $this->actingAsAdmin($this->userWithRole('operator'))->get('/admin/products?photo=missing')->assertForbidden();
    }

    public function test_endpoint_has_rate_limit_and_invalid_input_is_rejected(): void
    {
        $this->getJson('/catalogo/bloques?capacity=-1')->assertUnprocessable();
        for ($i = 0; $i < 120; $i++) {
            $response = $this->getJson('/catalogo/bloques');
        }
        $response->assertStatus(429);
    }

    public function test_export_isolated_browser_fixtures_from_real_views_and_endpoint(): void
    {
        Storage::fake('local');
        CatalogDataset::create(100);
        $actor = $this->adminUser();
        foreach (Product::orderByDesc('id')->limit(30)->get() as $product) {
            if ($product->id % 3) {
                $product->update(['capacity_gb' => 8]);
                app(DocumentService::class)->upload(new UploadedFile(resource_path('pdf/proposals/usb-metal.jpg'), 'prueba.jpg', 'image/jpeg', null, true), $product, $actor, 'product.image');
            }
        }
        $directory = storage_path('app/private/qa/catalog');
        File::ensureDirectoryExists($directory);
        $first = Product::orderByDesc('id')->first();
        $this->get(route('shop.thumbnail', $first->slug))->assertOk();
        File::put($directory.'/thumbnail.webp', Storage::disk('local')->get('product-thumbnails/v1/'.$first->image->checksum.'.webp'));
        $pages = [];
        $url = '/catalogo/bloques';
        do {
            $data = $this->getJson($url)->assertOk()->json();
            $pages[] = $data;
            $url = $data['next'] ? '/catalogo/bloques?'.parse_url($data['next'], PHP_URL_QUERY) : null;
        } while ($url);
        File::put($directory.'/catalog.html', $this->get('/catalogo')->assertOk()->getContent());
        File::put($directory.'/detail.html', $this->get('/producto/test000100')->assertOk()->getContent());
        File::put($directory.'/fixtures.json', json_encode(['pages' => $pages, 'filtered' => $this->getJson('/catalogo/bloques?q=TEST000001')->assertOk()->json(), 'empty' => $this->getJson('/catalogo/bloques?q=not-found')->assertOk()->json()]));
        $this->assertDatabaseCount('products', 100);
    }
}
