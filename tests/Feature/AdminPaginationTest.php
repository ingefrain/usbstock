<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithBaseAdmin;
use Tests\TestCase;

class AdminPaginationTest extends TestCase
{
    use InteractsWithBaseAdmin, RefreshDatabase;

    public function test_shared_pagination_preserves_filters_and_has_spanish_bounded_controls(): void
    {
        $this->actingAsAdmin();
        User::factory()->count(65)->create(['name' => 'Paginación ficticia']);
        $response = $this->get(route('admin.users.index', ['q' => 'Paginación', 'page' => 2, 'per_page' => 20]))->assertOk()
            ->assertSee('Mostrando 21–40 de 65')->assertSee('Ir a la página')->assertSee('Registros por página')->assertSee('Última')
            ->assertDontSee('Showing')->assertDontSee('Next')->assertSee('width="18" height="18"', false);
        $page = $response->viewData('users');
        $this->assertSame(20, $page->count());
        $this->assertStringContainsString('q=Paginaci', $page->nextPageUrl());
        $this->assertStringContainsString('per_page=20', $page->nextPageUrl());
        $this->get(route('admin.users.index', ['per_page' => 50, 'page' => 1]))->assertViewHas('users', fn ($p) => $p->perPage() === 50 && $p->currentPage() === 1);
        $this->get(route('admin.users.index'))->assertViewHas('users', fn ($p) => $p->perPage() === 50);
        $this->get(route('admin.roles.index'))->assertViewHas('roles', fn ($p) => $p->perPage() === 20);
    }

    public function test_invalid_huge_empty_and_out_of_range_parameters_remain_bounded(): void
    {
        $this->actingAsAdmin();
        User::factory()->count(41)->create();
        foreach (['100000000', '-1', 'bad', ['nested']] as $size) {
            $this->get(route('admin.users.index', ['per_page' => $size, 'page' => '9999999999999999999999999']))->assertOk()
                ->assertViewHas('users', fn ($p) => $p->perPage() === 20 && $p->currentPage() === 3 && $p->count() === 2);
        }
        $this->get(route('admin.users.index', ['page' => ['nested']]))->assertOk()->assertViewHas('users', fn ($p) => $p->currentPage() === 1);
        $this->get(route('admin.users.index', ['q' => 'no-existing-ux-user', 'page' => 90]))->assertOk()->assertSee('Sin resultados')
            ->assertViewHas('users', fn ($p) => $p->currentPage() === 1 && $p->total() === 0);
        $this->get(route('admin.products.index', ['per_page' => 200]))->assertOk()->assertViewHas('products', fn ($p) => $p->perPage() === 20);
        $this->get(route('admin.supplier-sync.index', ['per_page' => 100]))->assertOk()->assertViewHas('runs', fn ($p) => $p->perPage() === 20);
    }
}
