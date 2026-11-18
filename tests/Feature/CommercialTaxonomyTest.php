<?php

namespace Tests\Feature;

use App\Commerce\LegacyTaxonomy;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\Support\CatalogDataset;
use Tests\TestCase;

class CommercialTaxonomyTest extends TestCase
{
    use InteractsWithBaseAdmin, RefreshDatabase;

    private function source(): array
    {
        return ['source' => 'usbstock2', 'version' => 1, 'categories' => [
            ['id' => 10, 'parent' => 0, 'name' => 'Categoría ficticia', 'slug' => 'grupo', 'sort_order' => 1],
            ['id' => 11, 'parent' => 10, 'name' => 'Met&aacute;licas', 'slug' => 'grupo', 'sort_order' => 2],
            ['id' => 12, 'parent' => 10, 'name' => 'Otra clasificación', 'slug' => 'otra+clasificacion', 'sort_order' => 3],
        ], 'models' => [['id' => 77, 'key' => 'TEST000001', 'active' => 1]], 'relations' => [
            ['id' => 1, 'model_id' => 77, 'category_id' => 11], ['id' => 2, 'model_id' => 77, 'category_id' => 12],
        ]];
    }

    public function test_exact_multi_assignment_hierarchy_idempotence_and_personalizations(): void
    {
        CatalogDataset::create(2);
        $actor = $this->adminUser();
        $before = DB::table('products')->orderBy('id')->get()->toJson();
        $variants = DB::table('product_variants')->orderBy('id')->get()->toJson();
        $service = app(LegacyTaxonomy::class);
        $preview = $service->preview($this->source());
        $this->assertSame(1, $preview['classified_models']);
        $this->assertSame(['TEST000002'], $preview['unclassified']);
        $this->assertSame(2, count($preview['assignments']));
        $result = $service->apply($this->source(), $actor->id);
        $this->assertSame(3, $result['categories_created']);
        $this->assertSame(2, $result['relations_created']);
        $child = ProductCategory::where('source_id', 11)->sole();
        $this->assertSame('Metálicas', $child->name);
        $this->assertSame('grupo-11', $child->slug);
        $child->update(['name' => 'Personalización editorial', 'is_published' => false]);
        $again = $service->apply($this->source(), $actor->id);
        $this->assertSame(0, $again['categories_created']);
        $this->assertSame(0, $again['relations_created']);
        $this->assertSame('Personalización editorial', $child->fresh()->name);
        $this->assertFalse($child->fresh()->is_published);
        $this->assertSame($before, DB::table('products')->orderBy('id')->get()->toJson());
        $this->assertSame($variants, DB::table('product_variants')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('product_category_assignments', 2);
        $this->get('/catalogo/categoria/grupo')->assertOk()->assertSee('TEST000001')->assertDontSee('TEST000002');
        $this->get('/catalogo/categoria/grupo-11')->assertNotFound();
        ProductCategory::where('source_id', 10)->update(['is_published' => false]);
        $this->get('/catalogo/categoria/otra-clasificacion')->assertNotFound();
    }

    public function test_unverified_or_ambiguous_relationships_are_not_guessed(): void
    {
        CatalogDataset::create(1);
        $source = $this->source();
        $source['models'][] = ['id' => 78, 'key' => 'TEST000001', 'active' => 1];
        $preview = app(LegacyTaxonomy::class)->preview($source);
        $this->assertSame([], $preview['assignments']);
        $this->assertSame(['TEST000001'], $preview['ambiguous']);
        $source['categories'][0]['parent'] = 11;
        $this->expectException(\RuntimeException::class);
        app(LegacyTaxonomy::class)->preview($source);
    }

    public function test_admin_rejects_cycles_third_levels_and_keeps_manual_assignment_independent(): void
    {
        CatalogDataset::create(1);
        $this->actingAsAdmin();
        app(LegacyTaxonomy::class)->apply($this->source(), auth()->id());
        $root = ProductCategory::where('source_id', 10)->sole();
        $child = ProductCategory::where('source_id', 11)->sole();
        $data = ['name' => $root->name, 'slug' => $root->slug, 'is_published' => 1, 'parent_id' => $root->id];
        $this->post(route('admin.product-categories.save', $root), $data)->assertSessionHasErrors('parent_id');
        $this->post(route('admin.product-categories.save', $root), array_replace($data, ['parent_id' => $child->id]))->assertSessionHasErrors('parent_id');
        $this->post(route('admin.product-categories.save', $child), ['name' => 'Nombre editorial', 'slug' => $child->slug, 'is_published' => 1, 'parent_id' => $root->id, 'sort_order' => 7])->assertRedirect();
        $this->assertSame('Nombre editorial', $child->fresh()->name);
        $this->assertSame(7, $child->fresh()->sort_order);
        app(LegacyTaxonomy::class)->apply($this->source(), auth()->id());
        $this->assertSame(7, $child->fresh()->sort_order);
        $this->assertDatabaseCount('product_category_assignments', 2);
    }
}
