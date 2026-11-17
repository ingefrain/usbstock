<?php

namespace Tests\Feature;

use App\Commerce\SupplierDownloader;
use App\Commerce\SupplierSync;
use App\Models\AdminAuditEvent;
use App\Models\CommercialClient;
use App\Models\CommercialSetting;
use App\Models\OutboxMessage;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\SupplierSyncRun;
use App\Support\AdminAuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class CommerceAdminTest extends TestCase
{
    use InteractsWithBaseAdmin, RefreshDatabase;

    private function product(): Product
    {
        Storage::fake('local');
        $sync = app(SupplierSync::class);
        $sync->apply($sync->preview("SKU,PIEZAS,DESCRIPCION,PRECIO_SIN_IVA\nTEST1-A,500,Memoria USB ficticia para pruebas,10.00", 'fixture.csv', null), null);

        return Product::sole();
    }

    public function test_category_client_and_issuer_forms_persist_only_valid_configuration(): void
    {
        $this->actingAsAdmin();
        $this->post(route('admin.product-categories.save'), ['name' => 'Prueba', 'slug' => 'prueba', 'is_published' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $category = ProductCategory::sole();
        $this->post(route('admin.product-categories.save', $category), ['name' => 'Prueba actualizada', 'slug' => 'prueba', 'is_published' => 0])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($category->fresh()->is_published);
        $this->post(route('admin.clients.save'), ['name' => 'Contacto ficticio', 'email' => 'contacto@example.test'])->assertRedirect()->assertSessionHasNoErrors();
        $client = CommercialClient::sole();
        $this->post(route('admin.clients.save', $client), ['name' => 'Contacto actualizado', 'email' => 'contacto@example.test'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Contacto actualizado', $client->fresh()->name);
        $this->put(route('admin.commerce.settings.save'), ['issuer_name' => 'Emisor ficticio', 'issuer_contact' => 'Contacto ficticio', 'terms' => 'Condiciones de prueba'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Emisor ficticio', CommercialSetting::values()['issuer_name']);
        $this->put(route('admin.commerce.settings.save'), ['issuer_name' => ''])->assertSessionHasErrors(['issuer_name', 'issuer_contact']);
        $this->actingAsAdmin($this->userWithRole('operator'))->put(route('admin.commerce.settings.save'), [])->assertForbidden();
    }

    public function test_image_publication_and_impersonation_permissions(): void
    {
        $product = $this->product();
        $admin = $this->adminUser();
        $this->actingAsAdmin($admin);
        $this->put(route('admin.products.update', $product), ['is_published' => 1, 'custom_name' => 'USB de prueba', 'capacity_gb' => 16, 'image' => UploadedFile::fake()->image('prueba.png', 40, 40)])->assertRedirect()->assertSessionHasNoErrors();
        $image = $product->fresh()->image;
        $this->assertNotNull($image);
        $this->assertSame('image/webp', $image->mime_type);
        $this->get(route('shop.image', $product->slug))->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->put(route('admin.products.update', $product), ['is_published' => 0])->assertRedirect();
        $this->get(route('shop.image', $product->slug))->assertNotFound();
        $target = $this->userWithPermissions(['products.view', 'products.manage']);
        $actor = $this->userWithPermissions(['users.impersonate', 'products.view']);
        $this->actingAsAdmin($actor)->post(route('admin.users.impersonate', $target))->assertRedirect();
        $this->put(route('admin.products.update', $product), ['is_published' => 1])->assertForbidden();
        $this->get(route('admin.products.edit', $product))->assertOk();
        $this->post(route('admin.impersonation.stop'))->assertRedirect();
        $this->actingAsAdmin($admin)->post(route('admin.users.impersonate', $target))->assertRedirect();
        $this->put(route('admin.products.update', $product), ['is_published' => 1])->assertRedirect();
        $event = AdminAuditEvent::where('action', 'product.updated')->latest('id')->first();
        $this->assertSame($admin->id, $event->actor_id);
        $this->assertSame($target->id, $event->context['impersonation']['target_id']);
    }

    public function test_failed_apply_rolls_back_every_supplier_field(): void
    {
        $product = $this->product();
        $run = app(SupplierSync::class)->preview("SKU,PIEZAS,DESCRIPCION,PRECIO_SIN_IVA\nTEST1-A,450,Descripción cambiada,11.00", 'fixture.csv', null);
        $this->mock(AdminAuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Simulated audit failure'));
        try {
            app(SupplierSync::class)->apply($run, null);
            $this->fail('Expected failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated audit failure', $error->getMessage());
        }
        $this->assertSame('ready', $run->fresh()->status);
        $this->assertSame(500, ProductVariant::sole()->stock);
        $this->assertSame('Memoria USB ficticia para pruebas', $product->fresh()->supplier_description);
    }

    public function test_cli_connection_failure_is_audited_without_changing_catalog(): void
    {
        $this->product();
        $this->mock(SupplierDownloader::class)->shouldReceive('download')->once()->andThrow(new \RuntimeException('Private connection detail'));
        $this->artisan('supplier:sync', ['--fetch' => true])->assertExitCode(1);
        $this->assertSame(500, ProductVariant::sole()->stock);
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'supplier_sync.failed']);
        $this->assertStringNotContainsString('Private connection detail', AdminAuditEvent::all()->toJson());
    }

    public function test_operational_notifications_reuse_outbox_without_exposing_client_or_supplier_data(): void
    {
        Storage::fake('local');
        config(['commerce.notify_email' => 'operations@example.test']);
        $run = app(SupplierSync::class)->preview("SKU,PIEZAS,DESCRIPCION,PRECIO_SIN_IVA\nTEST1-A,100,Descripción privada,23.45", 'fixture.csv', null);
        app(SupplierSync::class)->apply($run, null);
        $this->assertDatabaseCount('outbox_messages', 2);
        $messages = OutboxMessage::all();
        $this->assertSame(['pending'], $messages->pluck('status')->unique()->values()->all());
        $this->assertStringNotContainsString('Descripción privada', $messages->toJson());
        $this->assertStringNotContainsString('23.45', $messages->toJson());
    }

    public function test_views_with_fixtures_are_usable_and_can_be_reviewed_without_demo_data(): void
    {
        $product = $this->product();
        $product->update(['is_published' => true, 'custom_name' => 'USB de prueba', 'capacity_gb' => 16]);
        $this->actingAsAdmin();
        $views = ['product' => $this->get(route('shop.product', $product->slug)), 'catalog-filled' => $this->get(route('shop.catalog')), 'quote-editor' => $this->get(route('admin.quotes.create')), 'product-editor' => $this->get(route('admin.products.edit', $product)), 'sync-preview' => $this->get(route('admin.supplier-sync.show', SupplierSyncRun::sole()))];
        foreach ($views as $name => $response) {
            $response->assertOk();
            if (getenv('USB_QA_ARTIFACTS') === '1') {
                $directory = storage_path('app/private/qa');
                if (! is_dir($directory)) {
                    mkdir($directory, 0700, true);
                } file_put_contents($directory.'/'.$name.'.html', str_replace('http://localhost', 'http://127.0.0.1:8017', $response->getContent()));
            }
        }
        $views['quote-editor']->assertSee('data-add-quote-line', false)->assertSee('TEST1-A');
        $views['product']->assertSee('Cotizar')->assertDontSee('10.00');
    }
}
