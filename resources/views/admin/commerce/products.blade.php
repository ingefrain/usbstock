@extends('layouts.admin')
@section('title', 'Productos')
@section('content')
<div class="admin-form-surface admin-form-surface-wide">
<div class="page-intro"><p class="muted">Información del proveedor y personalizaciones comerciales.</p><a class="button button-secondary" href="{{ route('admin.product-categories.index') }}">Categorías</a></div>
<section class="panel"><form method="GET" class="field-grid"><div class="field"><label for="q">Modelo o descripción</label><input id="q" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="120"></div><div class="field"><label for="status">Publicación</label><select id="status" name="status"><option value="">Todos</option><option value="published" @selected(($filters['status'] ?? '') === 'published')>Publicados</option><option value="draft" @selected(($filters['status'] ?? '') === 'draft')>Sin publicar</option></select></div><div class="form-actions"><button class="button button-primary">Buscar</button></div></form></section>
<section class="panel"><div class="table-wrap"><table><thead><tr><th>Modelo</th><th>Descripción</th><th>Variantes</th><th>Estado</th><th></th></tr></thead><tbody>
@forelse($products as $product)<tr><td><strong>{{ $product->name }}</strong><small class="table-subtitle">{{ $product->external_key }}</small></td><td>{{ \Illuminate\Support\Str::limit($product->description, 100) }}</td><td>{{ $product->variants_count }}</td><td>{{ $product->isPublic() ? 'Publicado' : 'Sin publicar' }}{{ !$product->provider_active ? ' · Proveedor inactivo' : '' }}</td><td><a class="button button-small button-secondary" href="{{ route('admin.products.edit', $product) }}">Ver producto</a></td></tr>
@empty<tr><td colspan="5">Aún no hay productos. Carga un archivo del proveedor desde Sincronizaciones.</td></tr>@endforelse
</tbody></table></div>{{ $products->links() }}</section></div>
@endsection
