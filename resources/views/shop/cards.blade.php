@forelse($products as $product)
    @include('shop.card')
@empty
<div class="empty">
    <h2>{{ count($filters ?? []) ? 'No encontramos modelos' : 'Estamos preparando el catálogo' }}</h2>
    <p>{{ count($filters ?? []) ? 'Prueba otra búsqueda o elimina algunos filtros.' : 'Pronto podrás explorar nuestros modelos. Podemos ayudarte con tu proyecto desde ahora.' }}</p>
    <a href="{{ count($filters ?? []) ? route('shop.catalog') : route('shop.info') }}" @if(count($filters ?? [])) data-catalog-clear @endif>{{ count($filters ?? []) ? 'Limpiar filtros' : 'Contactar a USB Stock' }}</a>
</div>
@endforelse
